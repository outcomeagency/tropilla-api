<?php

namespace App\Domain\Hacienda\Identificacion;

use App\Domain\Catalogos\Parametros;

/**
 * Hitos de identificación electrónica por especie y fecha (parámetro `identificacion_electronica_hitos`, TPA-63).
 * Son datos: el seeder los carga por especie y el establecimiento no los edita (son norma, no manejo).
 */
final class ReglasIdentificacion
{
    /** @return array{vigentes: list<array<string, string>>, proximos: list<array<string, string>>} */
    public static function para(string $especie, string $fecha, ?string $establecimientoId = null): array
    {
        $hitos = Parametros::valor('identificacion_electronica_hitos', $especie, null, $establecimientoId);
        $hitos = is_array($hitos) ? $hitos : [];
        usort($hitos, fn ($a, $b) => strcmp($a['desde'], $b['desde']));

        return [
            'vigentes' => array_values(array_filter($hitos, fn ($h) => $h['desde'] <= $fecha)),
            'proximos' => array_values(array_filter($hitos, fn ($h) => $h['desde'] > $fecha)),
        ];
    }
}
