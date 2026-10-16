<?php

namespace App\Http\Requests\V1\Establecimientos;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarProductorRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'row_version' => ['required', 'integer', 'min:1'],
            ...ReglasProductor::reglas(parcial: true),
        ];
    }
}
