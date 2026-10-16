<?php

namespace App\Casts;

use App\Support\Formato;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * `geography(Point,4326)` <-> ['lat' => '-27.4692', 'lon' => '-58.8306'] (decimales string, api-v1 §5).
 * Lectura: Postgres devuelve EWKB en hex. Escritura: EWKT (`SRID=4326;POINT(lon lat)`), que la
 * columna geography acepta como texto.
 *
 * @implements CastsAttributes<array{lat: string, lon: string}|null, array{lat: string|float, lon: string|float}|null>
 */
class PuntoGeografico implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (str_starts_with((string) $value, 'SRID=')) {
            preg_match('/POINT\(([-0-9.]+) ([-0-9.]+)\)/', (string) $value, $m);

            return ['lat' => Formato::decimal($m[2]), 'lon' => Formato::decimal($m[1])];
        }

        $bin = hex2bin((string) $value);
        if ($bin === false || strlen($bin) < 21) {
            return null;
        }
        $le = ord($bin[0]) === 1;
        $type = unpack($le ? 'V' : 'N', substr($bin, 1, 4))[1];
        $offset = ($type & 0x20000000) ? 9 : 5; // con SRID
        $x = unpack($le ? 'e' : 'E', substr($bin, $offset, 8))[1];
        $y = unpack($le ? 'e' : 'E', substr($bin, $offset + 8, 8))[1];

        return ['lat' => self::coord($y), 'lon' => self::coord($x)];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! isset($value['lat'], $value['lon']) || ! is_numeric($value['lat']) || ! is_numeric($value['lon'])) {
            throw new InvalidArgumentException('Punto inválido: se espera {lat, lon}.');
        }

        return sprintf('SRID=4326;POINT(%s %s)', $value['lon'], $value['lat']);
    }

    private static function coord(float $v): string
    {
        return (string) Formato::decimal(number_format($v, 7, '.', ''));
    }
}
