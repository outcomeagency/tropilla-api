<?php

namespace App\Domain\Documentos\Borradores;

/** Lectura de la lista de campos revisables de un borrador (rutas `fecha`, `categorias.0.existentes`…). */
final class Campos
{
    public const ESTADOS = ['pendiente', 'correcto', 'editado', 'no_legible', 'no_aplica'];

    /** Valor vigente del campo; `null` si no existe o se marcó no legible / no aplica. */
    public static function valor(array $campos, string $ruta): mixed
    {
        foreach ($campos as $c) {
            if ($c['ruta'] === $ruta) {
                return in_array($c['estado'], ['no_legible', 'no_aplica'], true) ? null : $c['valor'];
            }
        }

        return null;
    }

    /** Cantidad de filas de un grupo repetido (máximo índice + 1). */
    public static function filas(array $campos, string $grupo): int
    {
        $n = 0;
        foreach ($campos as $c) {
            if (preg_match('/^'.preg_quote($grupo, '/').'\.(\d+)\./', $c['ruta'], $m)) {
                $n = max($n, (int) $m[1] + 1);
            }
        }

        return $n;
    }

    public static function pendientes(array $campos): int
    {
        return count(array_filter($campos, fn ($c) => $c['estado'] === 'pendiente'));
    }
}
