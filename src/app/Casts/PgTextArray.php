<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Columna Postgres `text[]` <-> array PHP de strings.
 *
 * @implements CastsAttributes<list<string>, list<string>|null>
 */
class PgTextArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return self::parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : self::literal(array_values((array) $value));
    }

    /** @return list<string> */
    public static function parse(mixed $value): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }
        if (is_array($value)) {
            return array_values($value);
        }
        $inner = substr((string) $value, 1, -1);
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => isset($x[2]) && $x[2] !== '' ? $x[2] : stripcslashes($x[1]), $m);
    }

    /** @param list<string> $values */
    public static function literal(array $values): string
    {
        return '{'.implode(',', array_map(fn ($v) => '"'.addcslashes((string) $v, '"\\').'"', $values)).'}';
    }
}
