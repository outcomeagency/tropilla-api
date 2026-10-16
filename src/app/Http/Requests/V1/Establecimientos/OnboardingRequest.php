<?php

namespace App\Http\Requests\V1\Establecimientos;

use Illuminate\Foundation\Http\FormRequest;

class OnboardingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'productor' => ['required', 'array'],
            ...ReglasProductor::reglas('productor.'),
            'establecimiento' => ['required', 'array'],
            ...ReglasEstablecimiento::reglas('establecimiento.'),
        ];
    }
}
