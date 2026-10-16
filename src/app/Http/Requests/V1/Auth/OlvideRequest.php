<?php

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class OlvideRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'required_without:telefono', 'string', 'max:254'],
            'telefono' => ['nullable', 'required_without:email', 'string', 'max:20'],
        ];
    }
}
