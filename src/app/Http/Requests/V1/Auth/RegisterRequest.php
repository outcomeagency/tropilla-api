<?php

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // `cliente` es obligatorio en el contrato; se asume `web` si falta (compatibilidad).
        $this->mergeIfMissing(['cliente' => 'web']);
        if (is_string($this->input('email'))) {
            $this->merge(['email' => trim($this->input('email'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'required_without:telefono', 'email:rfc', 'max:254', 'unique:usuarios,email'],
            'telefono' => ['nullable', 'required_without:email', ReglasAuth::TELEFONO, 'unique:usuarios,telefono'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
            'idioma' => ['nullable', 'in:es,en,pt'],
            'cliente' => ['required', 'in:web,app'],
            ...ReglasAuth::dispositivo(),
        ];
    }
}
