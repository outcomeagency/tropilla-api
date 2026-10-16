<?php

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarUsuarioRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'idioma' => ['sometimes', 'required', 'in:es,en,pt'],
            'pais' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'foto_sha256' => ['sometimes', 'nullable', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }
}
