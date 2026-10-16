<?php

namespace App\Http\Requests\V1\Catalogos;

use Illuminate\Foundation\Http\FormRequest;

class CatalogoPropioRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lista' => ['required', 'in:razas,causas_muerte,signos_clinicos,principios_activos'],
            'nombre' => ['required', 'string', 'min:2', 'max:100'],
            'especie' => ['nullable', 'required_if:lista,razas', 'string', 'exists:especies,codigo'],
        ];
    }
}
