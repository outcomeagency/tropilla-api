<?php

declare(strict_types=1);

namespace App\Domain\Integridad;

/**
 * Árbol de Merkle RFC 6962 sobre eslabones (sync-e-integridad.md §6.1): hoja = SHA-256(0x00‖e),
 * nodo = SHA-256(0x01‖i‖d), corte en la mayor potencia de 2 estrictamente menor que n.
 * Pruebas de inclusión desde la hoja a la raíz: [{lado: i|d, hash}] (`i` = el hermano va a la izquierda).
 */
final class Merkle
{
    /** @param list<string> $hojasHex */
    public static function raiz(array $hojasHex): string
    {
        return bin2hex(self::mth($hojasHex));
    }

    /**
     * @param  list<string>  $hojas
     * @return list<array{lado: 'i'|'d', hash: string}>
     */
    public static function prueba(int $m, array $hojas): array
    {
        $n = count($hojas);
        if ($n === 1) {
            return [];
        }
        $k = self::split($n);

        return $m < $k
            ? [...self::prueba($m, array_slice($hojas, 0, $k)), ['lado' => 'd', 'hash' => bin2hex(self::mth(array_slice($hojas, $k)))]]
            : [...self::prueba($m - $k, array_slice($hojas, $k)), ['lado' => 'i', 'hash' => bin2hex(self::mth(array_slice($hojas, 0, $k)))]];
    }

    /**
     * Raíz y **todas** las pruebas de inclusión en O(n log n), de abajo hacia arriba (TPA-40): con la regla de RFC 6962 el
     * nodo impar del final sube sin hermano; da el mismo árbol que la recursión de referencia (test con n = 1..70).
     *
     * @param  list<string>  $hojasHex
     * @return array{raiz: string, pruebas: list<list<array{lado: 'i'|'d', hash: string}>>}
     */
    public static function arbol(array $hojasHex): array
    {
        $nivel = array_map(fn ($h) => self::hoja($h), $hojasHex);
        $n = count($nivel);
        $pruebas = array_fill(0, $n, []);
        $pos = range(0, $n - 1); // posición de cada hoja en el nivel actual
        while (count($nivel) > 1) {
            $siguiente = [];
            $c = count($nivel);
            for ($i = 0; $i < $c; $i += 2) {
                $siguiente[] = $i + 1 < $c ? self::nodo($nivel[$i], $nivel[$i + 1]) : $nivel[$i];
            }
            for ($m = 0; $m < $n; $m++) {
                $p = $pos[$m];
                $hermano = $p ^ 1;
                if ($hermano < $c) {
                    $pruebas[$m][] = ['lado' => $p % 2 === 1 ? 'i' : 'd', 'hash' => bin2hex($nivel[$hermano])];
                }
                $pos[$m] = intdiv($p, 2);
            }
            $nivel = $siguiente;
        }

        return ['raiz' => bin2hex($nivel[0]), 'pruebas' => $pruebas];
    }

    /** @param list<array{lado: string, hash: string}> $prueba */
    public static function verificar(string $hojaHex, array $prueba, string $raizHex): bool
    {
        $h = self::hoja($hojaHex);
        foreach ($prueba as $p) {
            $s = (string) hex2bin($p['hash']);
            $h = $p['lado'] === 'i' ? self::nodo($s, $h) : self::nodo($h, $s);
        }

        return hash_equals($raizHex, bin2hex($h));
    }

    /** @param list<string> $h */
    private static function mth(array $h): string
    {
        $n = count($h);
        if ($n === 1) {
            return self::hoja($h[0]);
        }
        $k = self::split($n);

        return self::nodo(self::mth(array_slice($h, 0, $k)), self::mth(array_slice($h, $k)));
    }

    private static function split(int $n): int
    {
        $k = 1;
        while ($k * 2 < $n) {
            $k *= 2;
        }

        return $k;
    }

    private static function hoja(string $hex): string
    {
        return hash('sha256', "\x00".hex2bin($hex), true);
    }

    private static function nodo(string $l, string $r): string
    {
        return hash('sha256', "\x01".$l.$r, true);
    }
}
