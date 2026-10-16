<?php

declare(strict_types=1);

namespace App\Domain\Integridad;

use JsonException;
use stdClass;

/**
 * Serialización canónica **TROPILLA-CJ/1** (sync-e-integridad.md §4, normativo): RFC 8785 restringido
 * — sin floats, claves ASCII `[A-Za-z0-9_.-]` ordenadas por bytes, miembros `null` de objetos omitidos,
 * strings tal como vienen (escapes mínimos, U+2028/2029 y `/` crudos). Tiene que dar **byte por byte**
 * lo mismo que la implementación Dart de la app: lo prueban los vectores de `tests/fixtures/vectores-hash-v1.json`.
 *
 * Importante en PHP: decodificar SIEMPRE con objetos (`decode()`), nunca `json_decode($x, true)`
 * (convierte `{}` en `[]`), ni `$request->json()` / el cast `array` del `sobre` guardado.
 */
final class CanonicalJson
{
    private const MAX_INT = 9007199254740991;

    /** Decodifica preservando objeto vs. lista (stdClass); los floats quedan float (encode los rechaza). */
    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CanonicalizacionInvalida('JSON inválido: '.$e->getMessage(), previous: $e);
        }
    }

    public static function encode(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            $v === true => 'true',
            $v === false => 'false',
            is_int($v) => abs($v) > self::MAX_INT
                ? throw new CanonicalizacionInvalida('entero fuera de rango')
                : (string) $v,
            is_float($v) => throw new CanonicalizacionInvalida('float no permitido: usar string decimal'),
            is_string($v) => self::str($v),
            is_array($v) && array_is_list($v) => '['.implode(',', array_map(self::encode(...), $v)).']',
            is_array($v), $v instanceof stdClass => self::obj((array) $v),
            default => throw new CanonicalizacionInvalida('tipo no soportado'),
        };
    }

    /** Huella = hex(SHA-256(UTF-8(canonico))). */
    public static function huella(mixed $v): string
    {
        return hash('sha256', self::encode($v));
    }

    /** Eslabón = hex(SHA-256(eslabon_anterior + "\n" + huella)). Solo lo calcula el servidor. */
    public static function eslabon(string $anterior, string $huella): string
    {
        return hash('sha256', $anterior."\n".$huella);
    }

    /** @param array<int|string, mixed> $o */
    private static function obj(array $o): string
    {
        $keys = array_map('strval', array_keys($o));
        sort($keys, SORT_STRING); // orden por bytes (claves ASCII)
        $parts = [];
        foreach ($keys as $k) {
            if (! preg_match('/^[A-Za-z0-9_.\-]+$/', $k)) {
                throw new CanonicalizacionInvalida("clave inválida: {$k}");
            }
            if ($o[$k] === null) {
                continue;
            }
            $parts[] = self::str($k).':'.self::encode($o[$k]);
        }

        return '{'.implode(',', $parts).'}';
    }

    private static function str(string $s): string
    {
        if (! mb_check_encoding($s, 'UTF-8')) {
            throw new CanonicalizacionInvalida('UTF-8 inválido');
        }

        // UNESCAPED_LINE_TERMINATORS: U+2028/U+2029 crudos (igual que Dart y RFC 8785).
        return json_encode($s, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }
}
