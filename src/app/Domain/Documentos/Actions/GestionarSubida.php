<?php

namespace App\Domain\Documentos\Actions;

use App\Domain\Documentos\Almacen\AlmacenObjetos;
use App\Domain\Documentos\Archivos;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Subida diferida (TPA-44, stack §4): cola separada de la de eventos en la app; acá solo se coordina.
 * - Iniciar: dedupe por SHA-256 (si ya está completo → `{estado: completo}` y no se sube de nuevo); si no, simple (un PUT)
 *   o multipart (partes de `parte_bytes`) con URLs prefirmadas que vencen. Reintentar = iniciar otra subida (la vieja vence).
 * - Completar: ensambla/cierra el multipart y **verifica el SHA-256 y los bytes** del objeto: si no coincide se borra
 *   (la clave ES el hash: nunca puede quedar contenido distinto bajo ella) y responde 422 `archivo_corrupto`.
 */
final class GestionarSubida
{
    public function __construct(private readonly AlmacenObjetos $almacen) {}

    /**
     * @param  array{sha256: string, bytes: int, mime: string, establecimiento_id: string}  $d
     * @return array<string, mixed> Subida
     */
    public function iniciar(User $usuario, array $d, ?string $dispositivoId): array
    {
        $sha = strtolower($d['sha256']);
        if (DB::table('archivos')->where('sha256', $sha)->where('estado', 'completo')->exists()) {
            return ['subida_id' => null, 'estado' => 'completo', 'modo' => null, 'url' => null, 'parte_bytes' => null, 'partes' => [], 'expira_en' => null];
        }

        return DB::transaction(function () use ($usuario, $d, $sha, $dispositivoId) {
            Archivos::referenciar($sha, $d['bytes'], $d['mime'], $usuario->id);
            DB::table('archivos')->where('sha256', $sha)->where('estado', '<>', 'completo')->update(['bytes' => $d['bytes'], 'mime' => $d['mime'], 'estado' => 'pendiente']);

            $id = (string) Str::uuid7();
            $clave = Archivos::clave($sha);
            $expira = now()->addHours((int) config('tropilla.archivos.subida_horas'));
            $tam = (int) config('tropilla.archivos.parte_bytes');
            $multipart = $d['bytes'] >= (int) config('tropilla.archivos.multipart_desde_bytes');
            $partes = $multipart ? (int) ceil($d['bytes'] / $tam) : 1;
            [$uploadId, $urls] = $multipart ? $this->almacen->iniciarMultipart($id, $clave, $d['mime'], $partes, $expira) : [null, []];

            DB::table('subidas')->insert([
                'id' => $id, 'archivo_sha256' => $sha, 'usuario_id' => $usuario->id, 'establecimiento_id' => $d['establecimiento_id'], 'dispositivo_id' => $dispositivoId,
                'modo' => $multipart ? 'multipart' : 'simple', 's3_upload_id' => $uploadId, 'partes' => $partes, 'parte_bytes' => $multipart ? $tam : null,
                'estado' => 'iniciada', 'expira_en' => $expira, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return [
                'subida_id' => $id, 'estado' => 'iniciada', 'modo' => $multipart ? 'multipart' : 'simple',
                'url' => $multipart ? null : $this->almacen->urlSimple($id, $clave, $d['mime'], $expira),
                'parte_bytes' => $multipart ? $tam : null,
                'partes' => array_map(fn ($n, $u) => ['numero' => $n, 'url' => $u], array_keys($urls), $urls),
                'expira_en' => Formato::fechaHora($expira),
            ];
        });
    }

    /**
     * @param  list<array{numero: int, etag: string}>  $partes
     * @return object fila de `archivos`
     */
    public function completar(object $subida, array $partes): object
    {
        $clave = Archivos::clave($subida->archivo_sha256);
        $archivo = DB::table('archivos')->where('sha256', $subida->archivo_sha256)->first();
        if ($archivo->estado === 'completo') {
            DB::table('subidas')->where('id', $subida->id)->update(['estado' => 'completada', 'updated_at' => now()]);

            return $archivo; // otra subida del mismo archivo ganó: idempotente
        }
        if ($subida->estado !== 'iniciada' || Carbon::parse($subida->expira_en)->isPast()) {
            throw new ApiException(422, 'subida_vencida', __('La subida venció o ya se cerró: iniciá otra.'));
        }
        if ($subida->modo === 'multipart') {
            if (count($partes) !== (int) $subida->partes) {
                throw ApiException::validacion(__('Faltan partes.'), ['partes' => [__('Se esperaban :n partes.', ['n' => $subida->partes])]]);
            }
            $this->almacen->completarMultipart($subida->id, $clave, (string) $subida->s3_upload_id, $partes);
        } else {
            $this->almacen->completarSimple($subida->id, $clave);
        }

        $h = $this->almacen->huella($clave);
        if ($h === null || $h['sha256'] !== $subida->archivo_sha256 || ($archivo->bytes !== null && (int) $archivo->bytes !== $h['bytes'])) {
            if ($h !== null) {
                $this->almacen->borrar($clave);
            }
            DB::table('subidas')->where('id', $subida->id)->update(['estado' => 'abortada', 'updated_at' => now()]);
            DB::table('archivos')->where('sha256', $subida->archivo_sha256)->update(['estado' => 'corrupto']);
            throw new ApiException(422, 'archivo_corrupto', __('El archivo subido no coincide con su SHA-256: se descartó. Volvé a subirlo.'));
        }

        DB::transaction(function () use ($subida, $h) {
            DB::table('archivos')->where('sha256', $subida->archivo_sha256)->update([
                'estado' => 'completo', 'bytes' => $h['bytes'], 'subido_por' => $subida->usuario_id, 'subido_en' => now(), 'verificado_en' => now(),
            ]);
            DB::table('subidas')->where('id', $subida->id)->update(['estado' => 'completada', 'updated_at' => now()]);
        });

        return DB::table('archivos')->where('sha256', $subida->archivo_sha256)->first();
    }

    /** Job horario: subidas vencidas → expirada (y se aborta el multipart en el almacén). */
    public function expirarVencidas(): int
    {
        $n = 0;
        foreach (DB::table('subidas')->where('estado', 'iniciada')->where('expira_en', '<', now())->cursor() as $s) {
            $this->almacen->abortar($s->id, Archivos::clave($s->archivo_sha256), $s->s3_upload_id);
            DB::table('subidas')->where('id', $s->id)->update(['estado' => 'expirada', 'updated_at' => now()]);
            $n++;
        }

        return $n;
    }
}
