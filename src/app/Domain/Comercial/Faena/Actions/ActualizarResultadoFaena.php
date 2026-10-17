<?php

namespace App\Domain\Comercial\Faena\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lo que llega o se decide después del romaneo (TPA-67): vincular la venta/el egreso, cargar la liquidación del frigorífico y
 * adjuntar liquidación y factura. Con `row_version` y auditoría (antes/después). Las reses no se editan: se anula y se vuelve
 * a cargar. Anular deja el registro en el historial.
 */
final class ActualizarResultadoFaena
{
    private const CAMPOS = ['operacion_id', 'egreso_evento_id', 'liquidacion', 'liquidacion_documento_id', 'factura_documento_id', 'observaciones'];

    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /** @param array<string, mixed> $d */
    public function handle(Establecimiento $est, User $u, object $r, array $d): object
    {
        if ($r->estado !== 'vigente') {
            throw new ApiException(409, 'estado_invalido', __('El resultado está anulado.'));
        }
        if ((int) $d['row_version'] !== (int) $r->row_version) {
            throw ApiException::conflictoRowVersion(['row_version' => (int) $r->row_version]);
        }
        $cambios = array_intersect_key($d, array_flip(self::CAMPOS));
        $final = [...(array) $r, ...$cambios];
        Referencias::validar($est, array_filter($final, fn ($v) => $v !== null));
        foreach (['operacion_id', 'egreso_evento_id', 'liquidacion_documento_id', 'factura_documento_id'] as $c) {
            if (isset($cambios[$c])) {
                $cambios[$c] = strtolower($cambios[$c]);
            }
        }
        if (array_key_exists('liquidacion', $cambios)) {
            $cambios['liquidacion'] = $cambios['liquidacion'] === null ? null : json_encode(Referencias::liquidacion($cambios['liquidacion']), JSON_UNESCAPED_UNICODE);
        }

        return DB::transaction(function () use ($est, $u, $r, $cambios) {
            $n = DB::table('resultados_faena')->where('id', $r->id)->where('row_version', $r->row_version)
                ->update([...$cambios, 'row_version' => $r->row_version + 1, 'updated_at' => now()]);
            if ($n === 0) {
                throw ApiException::conflictoRowVersion();
            }
            $this->auditoria->handle($est->id, $u->id, 'resultado_faena', $r->id, 'edicion', array_intersect_key((array) $r, $cambios), $cambios);

            return DB::table('resultados_faena')->where('id', $r->id)->first();
        });
    }

    public function anular(Establecimiento $est, User $u, object $r, string $motivo): object
    {
        if ($r->estado === 'anulado') {
            return $r;
        }
        DB::table('resultados_faena')->where('id', $r->id)->update([
            'estado' => 'anulado', 'anulado_por' => $u->id, 'anulado_en' => now(), 'anulado_motivo' => $motivo,
            'row_version' => $r->row_version + 1, 'updated_at' => now(),
        ]);
        $this->auditoria->handle($est->id, $u->id, 'resultado_faena', $r->id, 'anulacion', ['estado' => 'vigente'], ['estado' => 'anulado'], $motivo);

        return DB::table('resultados_faena')->where('id', $r->id)->first();
    }
}
