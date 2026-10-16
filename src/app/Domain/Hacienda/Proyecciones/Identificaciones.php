<?php

namespace App\Domain\Hacienda\Proyecciones;

/** Normalización para búsqueda y unicidad de identificaciones (modelo-datos §4.6). */
final class Identificaciones
{
    public static function normalizar(string $valor): string
    {
        return strtoupper((string) preg_replace('/[\s\-.\/]+/', '', $valor));
    }
}
