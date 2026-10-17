<?php

namespace App\Domain\Campos\Actions;

use App\Domain\Campos\Campos;
use App\Domain\Notificaciones\Notificador;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Proceso de disputa del mercado de campos (TPA-74):
 * 1) cualquier usuario reporta un aviso (o una consulta en la que participó) con motivo y detalle;
 * 2) el anunciante recibe aviso sin la identidad de quien reportó y puede dejar su descargo;
 * 3) con N reportes abiertos de personas distintas el aviso pasa a «en revisión» y deja de verse;
 * 4) el equipo de TROPILLA resuelve (resuelta/desestimada) con una acción: ninguna, pausar, cerrar o retirar la verificación.
 * TROPILLA no arbitra el contrato entre partes: modera la publicación.
 */
final class Disputas
{
    public const ACCIONES = ['ninguna' => 'Sin cambios en el aviso', 'aviso_pausado' => 'Aviso pausado', 'aviso_cerrado' => 'Aviso cerrado',
        'verificacion_retirada' => 'Verificación retirada'];

    public function __construct(private readonly Notificador $notificador, private readonly RegistrarAuditoria $auditoria) {}

    /** @param array{motivo: string, detalle: string, consulta_id?: ?string} $d */
    public function reportar(object $aviso, User $u, array $d): object
    {
        if (DB::table('membresias')->where('establecimiento_id', $aviso->establecimiento_id)->where('usuario_id', $u->id)->where('estado', 'activa')->exists()) {
            throw ApiException::reglaBloqueante('No podés reportar un aviso de tu propio establecimiento.');
        }
        if (! empty($d['consulta_id'])) {
            $participa = DB::table('campo_consultas')->where('id', $d['consulta_id'])->where('aviso_id', $aviso->id)
                ->whereIn('establecimiento_id', DB::table('membresias')->where('usuario_id', $u->id)->where('estado', 'activa')->select('establecimiento_id'))->exists();
            $participa || throw ApiException::validacion('La consulta no es tuya o no es de este aviso.', ['consulta_id' => ['La consulta no es tuya o no es de este aviso.']]);
        }
        $id = (string) Str::uuid7();
        $pasaARevision = DB::transaction(function () use ($aviso, $u, $d, $id) {
            $a = DB::table('campo_avisos')->where('id', $aviso->id)->lockForUpdate()->first();
            if (DB::table('campo_disputas')->where('aviso_id', $a->id)->where('denunciante_id', $u->id)->whereIn('estado', ['abierta', 'en_revision'])->exists()) {
                throw ApiException::reglaBloqueante('Ya reportaste este aviso; el equipo lo está revisando.');
            }
            DB::table('campo_disputas')->insert(['id' => $id, 'aviso_id' => $a->id, 'consulta_id' => $d['consulta_id'] ?? null, 'denunciante_id' => $u->id,
                'motivo' => $d['motivo'], 'detalle' => $d['detalle'], 'estado' => 'abierta', 'created_at' => now(), 'updated_at' => now()]);
            $abiertas = DB::table('campo_disputas')->where('aviso_id', $a->id)->whereIn('estado', ['abierta', 'en_revision'])->count();
            if ($a->estado === 'publicado' && $abiertas >= (int) config('tropilla.campos.umbral_revision')) {
                DB::table('campo_avisos')->where('id', $a->id)->update(['estado' => 'en_revision', 'row_version' => $a->row_version + 1, 'updated_at' => now()]);
                $this->auditoria->handle($a->establecimiento_id, null, 'campo_aviso', $a->id, 'en_revision', ['estado' => 'publicado'], ['estado' => 'en_revision'], "{$abiertas} reportes abiertos");

                return true;
            }

            return false;
        });
        $this->notificador->aRoles($aviso->establecimiento_id, Notificador::rolesCon('campos.gestionar'), 'campo_disputa', 'Reportaron tu aviso de campo',
            "«{$aviso->titulo}»: ".Campos::MOTIVOS_DISPUTA[$d['motivo']].'. '.($pasaARevision ? 'El aviso quedó en revisión. ' : '').'Podés dejar tu descargo.',
            ['disputa_id' => $id, 'aviso_id' => $aviso->id, 'ruta' => '/campos/mis-avisos'], "campo_disputa:{$id}");

        return DB::table('campo_disputas')->where('id', $id)->first();
    }

    public function descargo(object $disputa, User $u, string $texto): object
    {
        if (! in_array($disputa->estado, ['abierta', 'en_revision'], true)) {
            throw ApiException::reglaBloqueante('La disputa ya se resolvió.');
        }
        DB::table('campo_disputas')->where('id', $disputa->id)->update(['descargo' => $texto, 'descargo_en' => now(), 'updated_at' => now()]);
        $est = DB::table('campo_avisos')->where('id', $disputa->aviso_id)->value('establecimiento_id');
        $this->auditoria->handle($est, $u->id, 'campo_disputa', $disputa->id, 'descargo');

        return DB::table('campo_disputas')->where('id', $disputa->id)->first();
    }

    /** @param array{estado: string, resolucion?: ?string, accion?: ?string} $d */
    public function resolver(object $disputa, User $staff, array $d): object
    {
        return DB::transaction(function () use ($disputa, $staff, $d) {
            $x = DB::table('campo_disputas')->where('id', $disputa->id)->lockForUpdate()->first();
            if (! in_array($x->estado, ['abierta', 'en_revision'], true)) {
                throw ApiException::reglaBloqueante('La disputa ya se resolvió.');
            }
            $final = in_array($d['estado'], ['resuelta', 'desestimada'], true);
            $accion = $final ? ($d['accion'] ?? 'ninguna') : null;
            DB::table('campo_disputas')->where('id', $x->id)->update(['estado' => $d['estado'], 'resolucion' => $d['resolucion'] ?? $x->resolucion, 'accion' => $accion,
                'resuelta_por' => $final ? $staff->id : null, 'resuelta_en' => $final ? now() : null, 'updated_at' => now()]);
            $a = DB::table('campo_avisos')->where('id', $x->aviso_id)->lockForUpdate()->first();
            if ($final) {
                $this->aplicar($a, $accion);
                $this->notificar($x, $a, $d['estado'], $accion);
            }
            $this->auditoria->handle($a->establecimiento_id, $staff->id, 'campo_disputa', $x->id, $d['estado'], ['estado' => $x->estado], ['estado' => $d['estado'], 'accion' => $accion], $d['resolucion'] ?? null);

            return DB::table('campo_disputas')->where('id', $x->id)->first();
        });
    }

    public function verificar(object $aviso, User $staff, string $nivel, ?string $nota): object
    {
        DB::table('campo_avisos')->where('id', $aviso->id)->update(['verificacion' => $nivel, 'verificacion_nota' => $nota,
            'verificado_por' => $nivel === 'declarado' ? null : $staff->id, 'verificado_en' => $nivel === 'declarado' ? null : now(),
            'row_version' => $aviso->row_version + 1, 'updated_at' => now()]);
        $this->auditoria->handle($aviso->establecimiento_id, $staff->id, 'campo_aviso', $aviso->id, 'verificacion', ['verificacion' => $aviso->verificacion], ['verificacion' => $nivel], $nota);

        return DB::table('campo_avisos')->where('id', $aviso->id)->first();
    }

    private function aplicar(object $a, string $accion): void
    {
        $fila = match ($accion) {
            'aviso_pausado' => $a->estado === 'cerrado' ? [] : ['estado' => 'pausado'],
            'aviso_cerrado' => $a->estado === 'cerrado' ? [] : ['estado' => 'cerrado', 'cerrado_en' => now(), 'motivo_cierre' => 'moderacion'],
            'verificacion_retirada' => ['verificacion' => 'declarado', 'verificacion_nota' => 'Verificación retirada tras un reporte.', 'verificado_por' => null, 'verificado_en' => null],
            default => [],
        };
        // Sin acción y sin otros reportes abiertos: el aviso en revisión vuelve a estar publicado.
        $quedan = DB::table('campo_disputas')->where('aviso_id', $a->id)->whereIn('estado', ['abierta', 'en_revision'])->count();
        if ($a->estado === 'en_revision' && ! isset($fila['estado']) && $quedan === 0) {
            $fila['estado'] = 'publicado';
        }
        if ($fila !== []) {
            DB::table('campo_avisos')->where('id', $a->id)->update([...$fila, 'row_version' => $a->row_version + 1, 'updated_at' => now()]);
        }
    }

    private function notificar(object $x, object $a, string $estado, string $accion): void
    {
        $texto = ($estado === 'resuelta' ? 'Se resolvió' : 'Se desestimó')." el reporte sobre «{$a->titulo}». ".self::ACCIONES[$accion].'.';
        $this->notificador->aUsuario($x->denunciante_id, null, 'campo_disputa', 'Revisamos tu reporte', $texto, ['disputa_id' => $x->id, 'ruta' => '/campos'], "campo_disputa:{$x->id}:{$estado}");
        $this->notificador->aRoles($a->establecimiento_id, Notificador::rolesCon('campos.gestionar'), 'campo_disputa', 'Resolución sobre tu aviso de campo', $texto,
            ['disputa_id' => $x->id, 'aviso_id' => $a->id, 'ruta' => '/campos/mis-avisos'], "campo_disputa:{$x->id}:{$estado}");
    }
}
