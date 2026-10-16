<?php

namespace App\Http\Requests\V1\Establecimientos;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarEstablecimientoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $establecimiento = $this->route('establecimiento');

        return [
            'row_version' => ['required', 'integer', 'min:1'],
            'ficha_ampliada' => ['sometimes', 'array'],
            'ficha_ampliada.*' => ['nullable'],
            'visibilidad_ficha' => ['sometimes', 'array'],
            'visibilidad_ficha.*' => ['sometimes', 'boolean'],
            ...ReglasEstablecimiento::reglas(parcial: true, provincia: $establecimiento?->provincia_codigo),
            // cambiar de provincia obliga a mandar el departamento
            'departamento_codigo' => ['required_with:provincia_codigo', 'string', ...array_slice(ReglasEstablecimiento::reglas(parcial: true, provincia: $establecimiento?->provincia_codigo)['departamento_codigo'], 2)],
        ];
    }
}
