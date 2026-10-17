<?php

namespace App\Domain\Comercial\Faena\Actions;

use App\Domain\Comercial\Faena\Faena;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Support\Decimales as D;
use Illuminate\Support\Facades\DB;

/** Validación de referencias del resultado de faena (venta, egreso, documentos) y normalización de la liquidación (TPA-67). */
final class Referencias
{
    /** @param array<string, mixed> $d */
    public static function validar(Establecimiento $est, array $d): void
    {
        foreach (['documento_id', 'liquidacion_documento_id', 'factura_documento_id'] as $campo) {
            if (! empty($d[$campo]) && ! DB::table('documentos')->where('id', strtolower($d[$campo]))->where('establecimiento_id', $est->id)->exists()) {
                throw ApiException::validacion(__('El documento no existe en este establecimiento.'), [$campo => [__('No encontrado.')]]);
            }
        }
        if (! empty($d['operacion_id']) && ! empty($d['egreso_evento_id'])) {
            throw ApiException::validacion(__('Vinculá la venta o el egreso, no los dos.'), ['egreso_evento_id' => [__('Elegí uno.')]]);
        }
        self::operacion($est, $d['operacion_id'] ?? null);
        self::animalesEgreso($est, $d['egreso_evento_id'] ?? null);
    }

    public static function operacion(Establecimiento $est, ?string $id): ?object
    {
        if ($id === null) {
            return null;
        }
        $o = DB::table('operaciones')->where('id', strtolower($id))->where('establecimiento_id', $est->id)->first();
        if ($o === null || $o->tipo !== 'venta' || $o->estado !== 'registrada') {
            throw ApiException::validacion(__('La venta no existe, está anulada o es una compra.'), ['operacion_id' => [__('Inválida.')]]);
        }

        return $o;
    }

    /** Animales del egreso (versión vigente del evento). @return list<string> */
    public static function animalesEgreso(Establecimiento $est, ?string $raizId): array
    {
        if ($raizId === null) {
            return [];
        }
        $e = DB::table('eventos')->where('evento_raiz_id', strtolower($raizId))->where('establecimiento_id', $est->id)->where('en_revision', false)
            ->orderByDesc('version')->first();
        if ($e === null || $e->tipo !== 'hacienda.egreso' || $e->accion === 'anulacion') {
            throw ApiException::validacion(__('El egreso no existe o está anulado.'), ['egreso_evento_id' => [__('Inválido.')]]);
        }

        return DB::table('evento_animales')->where('evento_id', $e->id)->orderBy('animal_id')->pluck('animal_id')->all();
    }

    /**
     * Liquidación tal como la emitió el frigorífico o consignataria (dato oficial de la contraparte). Kg a 1 decimal, importes
     * a 2; el neto declarado se conserva aunque no cierre (la diferencia la muestra la conciliación).
     *
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    public static function liquidacion(array $l): array
    {
        $m = fn ($v) => $v === null ? null : D::redondear((string) $v, 2);

        return array_filter([
            'numero' => $l['numero'] ?? null, 'fecha' => $l['fecha'] ?? null, 'emisor' => $l['emisor'] ?? null,
            'kg' => isset($l['kg']) ? Faena::kg((string) $l['kg']) : null, 'precio_kg' => isset($l['precio_kg']) ? D::redondear((string) $l['precio_kg'], 4) : null,
            'bruto' => $m($l['bruto'] ?? null),
            'deducciones' => array_values(array_map(fn ($x) => ['concepto' => mb_substr(trim((string) $x['concepto']), 0, 120), 'importe' => $m($x['importe'])], $l['deducciones'] ?? [])),
            'neto' => $m($l['neto'] ?? null), 'moneda' => $l['moneda'] ?? 'ARS',
        ], fn ($v) => $v !== null);
    }
}
