<?php

namespace App\Domain\Hacienda\Services;

/**
 * Ocupación actual de potreros = derivada (modelo-datos §4.1): animales activos con ese potrero
 * (`animales_estado`) + cabezas anónimas de lotes ubicados ahí (`lotes_estado` + `lote_conteos`).
 * Se resuelve en una sola consulta para N potreros. Hasta que existan las proyecciones de
 * hacienda (TPA-22) devuelve ocupación vacía.
 */
final class OcupacionPotreros
{
    /**
     * @param  list<string>  $potreroIds
     * @return array<string, array{cabezas: int, lotes: list<array{id: string, nombre: string}>}>
     */
    public static function de(string $establecimientoId, array $potreroIds): array
    {
        return array_fill_keys($potreroIds, ['cabezas' => 0, 'lotes' => []]);
    }
}
