<?php

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class DispositivoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['nullable', 'uuid'],
            'plataforma' => ['sometimes', 'in:android,ios,web'],
            'nombre' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'fcm_token' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
