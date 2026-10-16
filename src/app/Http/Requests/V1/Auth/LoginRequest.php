<?php

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['cliente' => 'web']);
        if (is_string($this->input('email'))) {
            $this->merge(['email' => trim($this->input('email'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'required_without:telefono', 'string', 'max:254'],
            'telefono' => ['nullable', 'required_without:email', 'string', 'max:20'],
            'password' => ['required', 'string', 'max:200'],
            'cliente' => ['required', 'in:web,app'],
            ...ReglasAuth::dispositivo(),
        ];
    }
}
