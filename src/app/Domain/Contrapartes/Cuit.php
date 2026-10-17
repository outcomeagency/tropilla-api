<?php

namespace App\Domain\Contrapartes;

/** CUIT/CUIL: normalización, dígito verificador (módulo 11) y tipo de persona por prefijo. */
final class Cuit
{
    private const PESOS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function normalizar(string $cuit): string
    {
        return preg_replace('/\D/', '', $cuit) ?? '';
    }

    public static function valido(string $cuit): bool
    {
        $c = self::normalizar($cuit);
        if (strlen($c) !== 11 || ! in_array(substr($c, 0, 2), ['20', '23', '24', '25', '26', '27', '30', '33', '34'], true)) {
            return false;
        }
        $suma = 0;
        foreach (self::PESOS as $i => $peso) {
            $suma += (int) $c[$i] * $peso;
        }
        $dv = 11 - ($suma % 11);
        $dv = $dv === 11 ? 0 : ($dv === 10 ? 9 : $dv);

        return $dv === (int) $c[10];
    }

    /** `humana` (20/23/24/25/26/27) o `juridica` (30/33/34). La Ley 25.326 protege a personas humanas. */
    public static function tipoPersona(string $cuit): string
    {
        return in_array(substr(self::normalizar($cuit), 0, 2), ['30', '33', '34'], true) ? 'juridica' : 'humana';
    }

    public static function formatear(string $cuit): string
    {
        $c = self::normalizar($cuit);

        return strlen($c) === 11 ? substr($c, 0, 2).'-'.substr($c, 2, 8).'-'.substr($c, 10) : $c;
    }
}
