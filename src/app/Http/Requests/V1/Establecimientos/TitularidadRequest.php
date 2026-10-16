<?php

namespace App\Http\Requests\V1\Establecimientos;

use Illuminate\Foundation\Http\FormRequest;

class TitularidadRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'usuario_id' => ['required', 'uuid'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
