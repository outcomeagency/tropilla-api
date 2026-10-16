<?php

namespace App\Domain\Hacienda\Proyecciones;

/** Normalización para búsqueda y unicidad de identificaciones (modelo-datos §4.6). */
final class Identificaciones
{
    public static function normalizar(string $valor): string
    {
        return strtoupper((string) preg_replace('/[\s\-.\/]+/', '', $valor));
    }

    /**
     * Clave de comparación tolerante a ceros a la izquierda en cada tramo numérico («142» ≡ «0142», «U142» ≡ «U0142»).
     * Se aplica sobre un valor ya normalizado. `SQL_SIN_CEROS` es la misma regla del lado de Postgres.
     */
    public static function sinCeros(string $normalizado): string
    {
        return (string) preg_replace('/(^|\D)0+(\d)/', '$1$2', $normalizado);
    }

    public const SQL_SIN_CEROS = "regexp_replace(valor_normalizado, '(^|\\D)0+(\\d)', '\\1\\2', 'g')";
}
