<?php

namespace App\Domain\Hacienda\Proyecciones;

use Closure;

/**
 * Evaluador puro del DSL de condiciones (modelo-datos.md §3.2): nodos `todas` (AND), `alguna` (OR),
 * `no` (NOT) y hojas `{campo, op, valor}`; `valor` puede ser `{"param": clave}` (se resuelve por
 * especie/raza/establecimiento) y una hoja puede comparar un parámetro: `{param, op, valor}`.
 * Así la misma regla sirve para bovinos y búfalos con umbrales distintos.
 */
final class CondicionEvaluator
{
    /**
     * @param  array<string, mixed>  $nodo
     * @param  array<string, mixed>  $hechos  campos del animal / establecimiento
     * @param  Closure(string): mixed  $parametro
     */
    public static function evaluar(array $nodo, array $hechos, Closure $parametro): bool
    {
        if (isset($nodo['todas'])) {
            foreach ($nodo['todas'] as $n) {
                if (! self::evaluar($n, $hechos, $parametro)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($nodo['alguna'])) {
            foreach ($nodo['alguna'] as $n) {
                if (self::evaluar($n, $hechos, $parametro)) {
                    return true;
                }
            }

            return false;
        }
        if (isset($nodo['no'])) {
            return ! self::evaluar($nodo['no'], $hechos, $parametro);
        }

        $izq = isset($nodo['param']) ? $parametro($nodo['param']) : ($hechos[$nodo['campo']] ?? null);
        $der = is_array($nodo['valor'] ?? null) && isset($nodo['valor']['param']) ? $parametro($nodo['valor']['param']) : ($nodo['valor'] ?? null);

        return match ($nodo['op']) {
            'existe' => ($izq !== null) === ($der ?? true),
            'eq' => $izq !== null && $izq == $der,
            'ne' => $izq === null || $izq != $der,
            'in' => $izq !== null && in_array($izq, (array) $der, false),
            'lt' => $izq !== null && $der !== null && $izq < $der,
            'lte' => $izq !== null && $der !== null && $izq <= $der,
            'gt' => $izq !== null && $der !== null && $izq > $der,
            'gte' => $izq !== null && $der !== null && $izq >= $der,
            default => false,
        };
    }
}
