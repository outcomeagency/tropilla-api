<?php

namespace App\Domain\Comercial;

use App\Domain\Catalogos\Parametros;
use App\Support\Decimales as D;

/**
 * Simulador de liquidación en pie vs al gancho (TPA-45, modelo-datos §9.1). Fórmula **explícita** (duda 4 de Claude Design,
 * decisión asumida y visible en la respuesta):
 * - el **desbaste se aplica solo en pie** (al gancho el frigorífico liquida por la res: el rendimiento ya lo absorbe);
 * - el **rendimiento al gancho se aplica sobre el peso bruto**;
 * - **flete, arranque, seguro y comisión son del vendedor** en las dos modalidades (se restan del neto);
 * - el IVA es informativo (total facturado = subtotal + IVA); el neto es sin IVA.
 * Defaults por especie como datos: rendimiento `rendimiento_gancho_ref_pct` (BUB 53, BOV 55), IVA `iva_hacienda_pct` (10,5).
 * Redondeo: dinero a 2 decimales y kg a 1, half-up, al final de cada línea.
 */
final class SimuladorLiquidacion
{
    public const FORMULA = [
        'en_pie' => [
            'peso_neto_kg = peso_bruto_kg × (1 − desbaste_pct / 100)',
            'kg_liquidables = peso_neto_kg',
        ],
        'al_gancho' => [
            'peso_neto_kg = peso_bruto_kg (al gancho no se aplica desbaste)',
            'kg_liquidables = peso_bruto_kg × rendimiento_pct / 100',
        ],
        'comun' => [
            'subtotal = kg_liquidables × precio_kg',
            'iva = subtotal × iva_pct / 100 (informativo; total_facturado = subtotal + iva)',
            'comision = subtotal × comision_pct / 100 (a cargo del vendedor)',
            'gastos = flete + arranque + seguro (a cargo del vendedor)',
            'neto_total = subtotal − comision − gastos',
            'neto_kg_vivo = neto_total / peso_bruto_kg',
        ],
    ];

    /**
     * @param  array<string, mixed>  $e  SimulacionEntradas validadas (decimales como string)
     * @return array{entradas: array<string, mixed>, resultado: array<string, mixed>}
     */
    public function calcular(array $e, ?string $establecimientoId): array
    {
        $especie = $e['especie'] ?? null;
        $e['iva_pct'] ??= (string) (Parametros::valor('iva_hacienda_pct', $especie, null, $establecimientoId) ?? '10.5');
        foreach (['flete', 'arranque', 'seguro', 'comision_pct'] as $c) {
            $e[$c] ??= '0';
        }
        $e['moneda'] ??= 'ARS';
        if (isset($e['al_gancho'])) {
            $e['al_gancho']['rendimiento_pct'] ??= (string) (Parametros::valor('rendimiento_gancho_ref_pct', $especie, null, $establecimientoId) ?? '53');
            unset($e['al_gancho']['desbaste_pct']); // decisión: el desbaste es solo en pie
        }
        if (isset($e['en_pie'])) {
            $e['en_pie']['desbaste_pct'] ??= '0';
        }

        $pie = isset($e['en_pie']) ? $this->linea($e, $e['en_pie']['peso_bruto_kg'], D::mul($e['en_pie']['peso_bruto_kg'], D::sub('1', D::div($e['en_pie']['desbaste_pct'], '100'))), null, $e['en_pie']['precio_kg']) : null;
        $gancho = isset($e['al_gancho']) ? $this->linea($e, $e['al_gancho']['peso_bruto_kg'], $e['al_gancho']['peso_bruto_kg'], $e['al_gancho']['rendimiento_pct'], $e['al_gancho']['precio_kg']) : null;

        $diferencia = null;
        $conviene = null;
        if ($pie && $gancho) {
            $diferencia = D::redondear(D::sub($gancho['neto_total'], $pie['neto_total']), 2);
            $conviene = match (D::cmp($diferencia, '0')) {
                1 => 'al_gancho',
                -1 => 'en_pie',
                default => 'igual',
            };
        }

        return ['entradas' => $e, 'resultado' => ['en_pie' => $pie, 'al_gancho' => $gancho, 'diferencia_neto' => $diferencia, 'conviene' => $conviene,
            'formula' => self::FORMULA]];
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, string>
     */
    private function linea(array $e, string $bruto, string $neto, ?string $rendimiento, string $precio): array
    {
        $netoKg = D::redondear($neto, 1);
        $kg = $rendimiento === null ? $netoKg : D::redondear(D::pct($bruto, $rendimiento), 1);
        $subtotal = D::redondear(D::mul($kg, $precio), 2);
        $iva = D::redondear(D::pct($subtotal, $e['iva_pct']), 2);
        $comision = D::redondear(D::pct($subtotal, $e['comision_pct']), 2);
        $gastos = D::redondear(D::sum($e['flete'], $e['arranque'], $e['seguro']), 2);
        $netoTotal = D::redondear(D::sub(D::sub($subtotal, $comision), $gastos), 2);

        return [
            'peso_neto_kg' => $netoKg, 'kg_liquidables' => $kg, 'subtotal' => $subtotal, 'iva' => $iva,
            'total_facturado' => D::redondear(D::sum($subtotal, $iva), 2), 'comision' => $comision, 'gastos' => $gastos,
            'neto_total' => $netoTotal, 'neto_kg_vivo' => D::redondear(D::div($netoTotal, $bruto), 2),
        ];
    }
}
