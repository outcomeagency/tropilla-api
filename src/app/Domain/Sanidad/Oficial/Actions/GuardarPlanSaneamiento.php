<?php

namespace App\Domain\Sanidad\Oficial\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan de saneamiento (TPA-66, Res. 67/2019 / 128/2012): acciones con responsable y fecha objetivo, próximo muestreo y el
 * original del plan presentado. Uno activo por enfermedad; se edita con `row_version` (auditado) y se cierra como cumplido o anulado.
 */
final class GuardarPlanSaneamiento
{
    private const CAMPOS = ['inicio', 'proximo_muestreo', 'veterinario_nombre', 'veterinario_matricula', 'protocolo_id', 'documento_id', 'acciones', 'observaciones', 'estado', 'cerrado_el'];

    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /** @param array<string, mixed> $d */
    public function crear(Establecimiento $est, User $u, array $d): array
    {
        $id = strtolower($d['id'] ?? (string) Str::uuid7());
        if ($existente = DB::table('planes_saneamiento')->where('id', $id)->first()) {
            if ($existente->establecimiento_id !== $est->id) {
                throw new ApiException(409, 'id_reutilizado', __('Ese id ya se usó.'));
            }

            return [$existente, false];
        }
        if (DB::table('planes_saneamiento')->where('establecimiento_id', $est->id)->where('enfermedad', $d['enfermedad'])->where('estado', 'activo')->exists()) {
            throw new ApiException(409, 'plan_activo', __('Ya hay un plan de saneamiento activo para esta enfermedad: editalo o cerralo.'));
        }
        $this->referencias($est, $d);

        $p = DB::transaction(function () use ($est, $u, $d, $id) {
            DB::table('planes_saneamiento')->insert([
                'id' => $id, 'establecimiento_id' => $est->id, 'enfermedad' => $d['enfermedad'], 'inicio' => $d['inicio'],
                'proximo_muestreo' => $d['proximo_muestreo'] ?? null, 'veterinario_nombre' => $d['veterinario_nombre'] ?? null,
                'veterinario_matricula' => $d['veterinario_matricula'] ?? null, 'protocolo_id' => isset($d['protocolo_id']) ? strtolower($d['protocolo_id']) : null,
                'documento_id' => isset($d['documento_id']) ? strtolower($d['documento_id']) : null, 'acciones' => json_encode(self::acciones($d['acciones'] ?? []), JSON_UNESCAPED_UNICODE),
                'observaciones' => $d['observaciones'] ?? null, 'creado_por' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->auditoria->handle($est->id, $u->id, 'plan_saneamiento', $id, 'alta', null, ['enfermedad' => $d['enfermedad'], 'inicio' => $d['inicio']]);

            return DB::table('planes_saneamiento')->where('id', $id)->first();
        });

        return [$p, true];
    }

    /** @param array<string, mixed> $d */
    public function actualizar(Establecimiento $est, User $u, object $p, array $d): object
    {
        if ($p->estado !== 'activo') {
            throw new ApiException(409, 'estado_invalido', __('El plan ya está cerrado.'));
        }
        if ((int) $d['row_version'] !== (int) $p->row_version) {
            throw ApiException::conflictoRowVersion(['row_version' => (int) $p->row_version]);
        }
        $this->referencias($est, $d);
        $cambios = array_intersect_key($d, array_flip(self::CAMPOS));
        if (isset($cambios['acciones'])) {
            $cambios['acciones'] = json_encode(self::acciones($cambios['acciones']), JSON_UNESCAPED_UNICODE);
        }
        if (in_array($cambios['estado'] ?? 'activo', ['cumplido', 'anulado'], true)) {
            $cambios['cerrado_el'] ??= now($est->zona_horaria)->toDateString();
        }
        foreach (['protocolo_id', 'documento_id'] as $c) {
            if (isset($cambios[$c])) {
                $cambios[$c] = strtolower($cambios[$c]);
            }
        }

        return DB::transaction(function () use ($est, $u, $p, $cambios) {
            $n = DB::table('planes_saneamiento')->where('id', $p->id)->where('row_version', $p->row_version)
                ->update([...$cambios, 'row_version' => $p->row_version + 1, 'updated_at' => now()]);
            if ($n === 0) {
                throw ApiException::conflictoRowVersion();
            }
            $antes = array_intersect_key((array) $p, $cambios);
            $this->auditoria->handle($est->id, $u->id, 'plan_saneamiento', $p->id, 'edicion', $antes, $cambios);

            return DB::table('planes_saneamiento')->where('id', $p->id)->first();
        });
    }

    /** @param array<string, mixed> $d */
    private function referencias(Establecimiento $est, array $d): void
    {
        if (! empty($d['protocolo_id']) && ! DB::table('protocolos_sanitarios')->where('id', strtolower($d['protocolo_id']))->where('establecimiento_id', $est->id)->exists()) {
            throw ApiException::validacion(__('El protocolo no existe en este establecimiento.'), ['protocolo_id' => [__('No encontrado.')]]);
        }
        if (! empty($d['documento_id']) && ! DB::table('documentos')->where('id', strtolower($d['documento_id']))->where('establecimiento_id', $est->id)->where('privacidad', '<>', 'fiscal')->exists()) {
            throw ApiException::validacion(__('El documento no existe en este establecimiento.'), ['documento_id' => [__('No encontrado.')]]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $acciones
     * @return list<array<string, mixed>>
     */
    private static function acciones(array $acciones): array
    {
        return array_values(array_map(fn ($a) => [
            'descripcion' => mb_substr(trim((string) $a['descripcion']), 0, 300), 'responsable' => isset($a['responsable']) ? mb_substr((string) $a['responsable'], 0, 120) : null,
            'fecha_objetivo' => $a['fecha_objetivo'] ?? null, 'hecha_el' => $a['hecha_el'] ?? null,
        ], $acciones));
    }
}
