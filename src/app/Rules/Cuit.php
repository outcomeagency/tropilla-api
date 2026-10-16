<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** CUIT/CUIL argentino: 11 dígitos (con o sin guiones) y dígito verificador módulo 11. */
class Cuit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::valido($value)) {
            $fail(__('El CUIT no es válido.'));
        }
    }

    public static function valido(string $cuit): bool
    {
        if (! preg_match('/^\d{2}-?\d{8}-?\d$/', $cuit)) {
            return false;
        }
        $d = preg_replace('/\D/', '', $cuit);
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        foreach ($pesos as $i => $p) {
            $suma += (int) $d[$i] * $p;
        }
        $v = 11 - ($suma % 11);
        $v = $v === 11 ? 0 : ($v === 10 ? 9 : $v);

        return $v === (int) $d[10];
    }
}
