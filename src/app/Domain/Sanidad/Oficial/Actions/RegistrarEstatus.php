<?php

namespace App\Domain\Sanidad\Oficial\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Estatus sanitario del establecimiento por enfermedad (TPA-66): libre, en saneamiento, en certificación… con certificado,
 * autoridad emisora y vigencia. Uno vigente por enfermedad; cargar otro **reemplaza** al anterior (queda en el historial).
 * Es la copia del productor: `origen` distingue transcripción revisada (con original) de declaración sin respaldo.
 */
final class RegistrarEstatus
{
    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /**
     * @param  array<string, mixed>  $d
     * @return array{0: object, 1: bool}
     */
    public function handle(Establecimiento $est, User $u, array $d): array
    {
        $id = strtolower($d['id'] ?? (string) Str::uuid7());
        if ($existente = DB::table('estatus_sanitarios')->where('id', $id)->first()) {
            if ($existente->establecimiento_id !== $est->id) {
                throw new ApiException(409, 'id_reutilizado', __('Ese id ya se usó.'));
            }

            return [$existente, false];
        }
        if ($d['origen'] === 'transcripcion_revisada' && empty($d['documento_id'])) {
            throw ApiException::validacion(__('Una transcripción revisada lleva el certificado original.'), ['documento_id' => [__('Adjuntá el original.')]]);
        }
        if (! empty($d['documento_id']) && ! DB::table('documentos')->where('id', strtolower($d['documento_id']))->where('establecimiento_id', $est->id)->where('privacidad', '<>', 'fiscal')->exists()) {
            throw ApiException::validacion(__('El documento no existe en este establecimiento.'), ['documento_id' => [__('No encontrado.')]]);
        }
        if (! empty($d['protocolo_id'])) {
            $p = DB::table('protocolos_sanitarios')->where('id', strtolower($d['protocolo_id']))->where('establecimiento_id', $est->id)->first();
            if ($p === null || $p->estado !== 'vigente' || $p->enfermedad !== $d['enfermedad']) {
                throw ApiException::validacion(__('El protocolo no existe, está anulado o es de otra enfermedad.'), ['protocolo_id' => [__('Inválido.')]]);
            }
        }

        $e = DB::transaction(function () use ($est, $u, $d, $id) {
            $anterior = DB::table('estatus_sanitarios')->where('establecimiento_id', $est->id)->where('enfermedad', $d['enfermedad'])
                ->where('estado', 'vigente')->lockForUpdate()->first();
            if ($anterior !== null) {
                DB::table('estatus_sanitarios')->where('id', $anterior->id)->update(['estado' => 'reemplazado', 'reemplazado_por' => $id, 'updated_at' => now()]);
            }
            DB::table('estatus_sanitarios')->insert([
                'id' => $id, 'establecimiento_id' => $est->id, 'enfermedad' => $d['enfermedad'], 'estatus' => $d['estatus'], 'origen' => $d['origen'],
                'certificado_numero' => $d['certificado_numero'] ?? null, 'autoridad' => $d['autoridad'] ?? null, 'emitido_el' => $d['emitido_el'] ?? null,
                'vigente_desde' => $d['vigente_desde'], 'vence_el' => $d['vence_el'] ?? null, 'protocolo_id' => isset($d['protocolo_id']) ? strtolower($d['protocolo_id']) : null,
                'documento_id' => isset($d['documento_id']) ? strtolower($d['documento_id']) : null, 'observaciones' => $d['observaciones'] ?? null,
                'creado_por' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->auditoria->handle($est->id, $u->id, 'estatus_sanitario', $id, 'alta', $anterior ? ['id' => $anterior->id, 'estatus' => $anterior->estatus] : null,
                ['enfermedad' => $d['enfermedad'], 'estatus' => $d['estatus'], 'origen' => $d['origen'], 'vence_el' => $d['vence_el'] ?? null]);

            return DB::table('estatus_sanitarios')->where('id', $id)->first();
        });

        return [$e, true];
    }

    /** Anular (carga equivocada): vuelve a quedar vigente el que reemplazó, si existe. */
    public function anular(Establecimiento $est, User $u, object $e, string $motivo): object
    {
        if ($e->estado === 'anulado') {
            return $e;
        }

        return DB::transaction(function () use ($est, $u, $e, $motivo) {
            $e = DB::table('estatus_sanitarios')->where('id', $e->id)->lockForUpdate()->first();
            DB::table('estatus_sanitarios')->where('id', $e->id)->update([
                'estado' => 'anulado', 'anulado_por' => $u->id, 'anulado_en' => now(), 'anulado_motivo' => $motivo, 'updated_at' => now(),
            ]);
            if ($e->estado === 'vigente') {
                DB::table('estatus_sanitarios')->where('reemplazado_por', $e->id)->where('estado', 'reemplazado')
                    ->update(['estado' => 'vigente', 'reemplazado_por' => null, 'updated_at' => now()]);
            }
            $this->auditoria->handle($est->id, $u->id, 'estatus_sanitario', $e->id, 'anulacion', ['estado' => $e->estado], ['estado' => 'anulado'], $motivo);

            return DB::table('estatus_sanitarios')->where('id', $e->id)->first();
        });
    }
}
