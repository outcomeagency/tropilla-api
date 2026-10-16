<?php

namespace App\Rules;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Validación contra catálogos (reglas como datos): códigos globales o propios del establecimiento. */
final class Catalogo
{
    /** Código de `catalogo_items` de la lista (global o propio del establecimiento). */
    public static function existe(string $lista, ?string $establecimientoId = null): Exists
    {
        return Rule::exists('catalogo_items', 'codigo')->where('lista', $lista)->where('activo', true)
            ->where(fn ($q) => $q->whereNull('establecimiento_id')->when($establecimientoId, fn ($q2) => $q2->orWhere('establecimiento_id', $establecimientoId)));
    }
}
