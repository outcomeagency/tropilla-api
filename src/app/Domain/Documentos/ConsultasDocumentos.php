<?php

namespace App\Domain\Documentos;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Integridad\Confianza;
use App\Support\Formato;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Lecturas de la carpeta documental (TPA-36): visibilidad por rol (fiscal solo con `documentos.fiscal`) y estado derivado. */
final class ConsultasDocumentos
{
    public static function base(string $establecimientoId, bool $verFiscal): Builder
    {
        return DB::table('documentos as d')->where('d.establecimiento_id', $establecimientoId)
            ->when(! $verFiscal, fn ($q) => $q->where('d.privacidad', '<>', 'fiscal'));
    }

    public static function estado(object $d, string $hoy): string
    {
        return $d->estado === 'revocado' ? 'revocado' : ($d->vence_en !== null && $d->vence_en < $hoy ? 'vencido' : 'vigente');
    }

    /**
     * Schema `Documento` (con `versiones` solo en la ficha).
     *
     * @return array<string, mixed>
     */
    public static function documento(object $d, string $hoy, bool $conVersiones = false): array
    {
        $vigente = DB::table('documento_versiones')->where('documento_id', $d->id)->where('version', $d->version_vigente)->where('en_revision', false)->first();
        $vinculos = DB::table('documento_vinculos')->where('documento_id', $d->id)->orderBy('entidad_tipo')->orderBy('entidad_id')->get()
            ->map(fn ($v) => ['tipo' => $v->entidad_tipo, 'id' => $v->entidad_id, 'etiqueta' => self::etiqueta($v->entidad_tipo, $v->entidad_id)])->all();
        $enlaces = DB::getSchemaBuilder()->hasTable('enlaces_compartidos')
            ? DB::table('enlaces_compartidos')->where('documento_id', $d->id)->whereNull('revocado_en')->where('expira_en', '>', now())->count() : 0;

        $out = [
            'id' => $d->id,
            'tipo' => $d->tipo_codigo,
            'tipo_nombre' => (string) Catalogos::nombre(DB::table('tipos_documento')->where('codigo', $d->tipo_codigo)->value('nombres'), app()->getLocale()),
            'privacidad' => $d->privacidad,
            'titulo' => $d->titulo,
            'emisor_nombre' => $d->emisor_nombre,
            'emitido_en' => $d->emitido_en,
            'vence_en' => $d->vence_en,
            'estado' => self::estado($d, $hoy),
            'visibilidad' => $d->visibilidad,
            'enlaces_activos' => $enlaces,
            'version_vigente' => (int) $d->version_vigente,
            'confianza' => Confianza::deRegistro($vigente ? (int) $vigente->registro_integridad_id : null),
            'vinculos' => $vinculos,
            'versiones' => $conVersiones ? self::versiones($d->id) : null,
            'verificacion_url' => $vigente ? config('tropilla.web_url').'/v/'.$vigente->hash : null,
            'row_version' => (int) $d->row_version,
        ];
        if (! $conVersiones) {
            unset($out['versiones']);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> versiones (todas, incluidas las en revisión), de la más nueva a la más vieja */
    public static function versiones(string $documentoId): array
    {
        return DB::table('documento_versiones as v')->join('usuarios as u', 'u.id', '=', 'v.autor_id')->where('v.documento_id', $documentoId)
            ->orderByDesc('v.version')->orderBy('v.registrado_en')->get(['v.*', 'u.nombre as autor_nombre'])
            ->map(function ($v) {
                $rol = DB::table('membresias')->where('establecimiento_id', $v->establecimiento_id)->where('usuario_id', $v->autor_id)->orderByRaw("estado = 'activa' DESC")->value('rol');

                return [
                    'id' => $v->id, 'version' => (int) $v->version, 'accion' => $v->accion, 'motivo' => $v->motivo, 'tipo' => $v->tipo_codigo,
                    'privacidad' => $v->privacidad, 'titulo' => $v->titulo,
                    'emisor' => $v->emisor_nombre || $v->emisor_identificacion ? ['nombre' => $v->emisor_nombre, 'identificacion' => $v->emisor_identificacion] : null,
                    'emitido_en' => $v->emitido_en, 'vence_en' => $v->vence_en,
                    'vinculos' => json_decode($v->vinculos, true) ?: [],
                    'archivos' => DB::table('documento_version_archivos as va')->join('archivos as a', 'a.sha256', '=', 'va.archivo_sha256')
                        ->where('va.documento_version_id', $v->id)->orderBy('va.orden')->get(['a.*'])
                        ->map(fn ($a) => ['sha256' => $a->sha256, 'bytes' => $a->bytes === null ? null : (int) $a->bytes, 'mime' => $a->mime, 'estado' => $a->estado, 'url' => Archivos::url($a->sha256)])->all(),
                    'autor' => ['id' => $v->autor_id, 'nombre' => $v->autor_nombre, 'rol' => $rol],
                    'creado_en' => Formato::fechaHora(Carbon::parse($v->creado_en)),
                    'registrado_en' => Formato::fechaHora(Carbon::parse($v->registrado_en)),
                    'en_revision' => (bool) $v->en_revision,
                    'huella' => $v->hash,
                    'confianza' => Confianza::deRegistro((int) $v->registro_integridad_id),
                ];
            })->all();
    }

    private static function etiqueta(string $tipo, string $id): string
    {
        return (string) match ($tipo) {
            'animal' => DB::table('animales_estado')->where('animal_id', $id)->value('identificacion_principal') ?? 'Animal',
            'lote' => DB::table('lotes')->where('id', $id)->value('nombre'),
            'potrero' => DB::table('potreros')->where('id', $id)->value('nombre'),
            'establecimiento' => DB::table('establecimientos')->where('id', $id)->value('nombre'),
            'evento' => ($t = DB::table('eventos')->where('id', $id)->value('tipo')) ? (Catalogos::nombre(DB::table('tipos_evento')->where('codigo', $t)->value('nombres'), app()->getLocale()) ?? $t) : 'Evento',
            default => ucfirst($tipo),
        };
    }
}
