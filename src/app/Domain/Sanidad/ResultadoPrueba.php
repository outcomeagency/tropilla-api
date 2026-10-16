<?php

namespace App\Domain\Sanidad;

use App\Domain\Catalogos\Parametros;
use Illuminate\Support\Facades\DB;

/**
 * Resultado calculado de una prueba diagnóstica (functional/03 §5). Método `diferencia_mm` (tuberculina): d = lectura 72 h − 0 h;
 * negativo si d < `<prueba>_negativo_lt_mm`, positivo si d ≥ `<prueba>_positivo_gte_mm`, si no sospechoso. Los umbrales son
 * **parámetros** (por especie/raza/establecimiento, el vet los ajusta) con respaldo en `tipos_prueba.umbrales`.
 */
final class ResultadoPrueba
{
    /** @return array{resultado: string, umbrales_version: int, diferencia_mm: string, negativo_lt: string, positivo_gte: string}|null */
    public static function calcular(string $prueba, ?string $especie, ?string $raza, string $establecimientoId, ?string $lectura0, ?string $lectura72): ?array
    {
        $tipo = DB::table('tipos_prueba')->where('codigo', $prueba)->first();
        if ($tipo === null || $tipo->metodo_calculo !== 'diferencia_mm' || $lectura0 === null || $lectura72 === null) {
            return null;
        }
        $base = json_decode((string) $tipo->umbrales, true) ?: [];
        $neg = (string) (Parametros::valor("{$prueba}_negativo_lt_mm", $especie, $raza, $establecimientoId) ?? $base['negativo_lt'] ?? '3');
        $pos = (string) (Parametros::valor("{$prueba}_positivo_gte_mm", $especie, $raza, $establecimientoId) ?? $base['positivo_gte'] ?? '5');
        $d = bcsub($lectura72, $lectura0, 1);

        $resultado = bccomp($d, $pos, 1) >= 0 ? 'positivo' : (bccomp($d, $neg, 1) < 0 ? 'negativo' : 'sospechoso');

        return ['resultado' => $resultado, 'umbrales_version' => (int) $tipo->version, 'diferencia_mm' => $d, 'negativo_lt' => $neg, 'positivo_gte' => $pos];
    }
}
