<?php

namespace App\Http\Requests\V1\Hacienda;

use Illuminate\Foundation\Http\FormRequest;

/** AltaRapidaInput. El detalle de `animal` lo valida el JSON Schema de `hacienda.alta` (animal_nuevo). */
class AltaRapidaRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['nullable', 'uuid'],
            'animal' => ['required', 'array'],
            'animal.animal_id' => ['nullable', 'uuid'],
            'animal.especie' => ['required', 'string', 'exists:especies,codigo'],
            'animal.sexo' => ['required', 'in:M,H'],
            'animal.categoria' => ['required', 'string', 'max:64'],
            'ocurrido_en' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}\.\d{3}Z)?$/'],
            'confirmar_advertencias' => ['nullable', 'boolean'],
        ];
    }
}
