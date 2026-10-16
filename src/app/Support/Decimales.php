<?php

namespace App\Support;

/** Aritmética decimal exacta (bcmath) para dinero y kg: nada de floats. Redondeo half-up (lejos del cero). */
final class Decimales
{
    private const ESCALA = 10;

    public static function sum(string ...$v): string
    {
        return array_reduce($v, fn ($a, $b) => bcadd($a, $b, self::ESCALA), '0');
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::ESCALA);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::ESCALA);
    }

    public static function div(string $a, string $b): string
    {
        return bccomp($b, '0', self::ESCALA) === 0 ? '0' : bcdiv($a, $b, self::ESCALA);
    }

    /** a × pct / 100 */
    public static function pct(string $a, string $pct): string
    {
        return bcdiv(bcmul($a, $pct, self::ESCALA), '100', self::ESCALA);
    }

    public static function redondear(string $v, int $decimales): string
    {
        $mitad = '0.'.str_repeat('0', $decimales).'5';
        $r = bccomp($v, '0', self::ESCALA) >= 0 ? bcadd($v, $mitad, $decimales) : bcsub($v, $mitad, $decimales);

        return (string) Formato::decimal($r);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::ESCALA);
    }
}
