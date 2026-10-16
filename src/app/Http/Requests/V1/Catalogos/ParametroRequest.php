<?php

namespace App\Http\Requests\V1\Catalogos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ParametroInput: el tipo del valor tiene que coincidir con el del parámetro global (número, bool, texto u objeto). */
class ParametroRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'valor' => ['present'],
            'especie' => ['nullable', 'string', 'exists:especies,codigo'],
            'raza' => ['nullable', 'string', Rule::exists('razas', 'codigo')],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }
}
