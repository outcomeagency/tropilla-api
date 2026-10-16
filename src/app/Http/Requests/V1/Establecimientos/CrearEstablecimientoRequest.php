<?php

namespace App\Http\Requests\V1\Establecimientos;

use Illuminate\Foundation\Http\FormRequest;

class CrearEstablecimientoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'productor_id' => ['required', 'uuid'],
            ...ReglasEstablecimiento::reglas(),
        ];
    }
}
