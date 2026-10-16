<?php

namespace App\Http\Requests\V1\Sanidad;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** AplicarPlantillasInput: exige aceptar explícitamente «Validá este plan con tu veterinario». */
class AplicarPlantillasRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('plan.editar', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plantillas' => ['required', 'array', 'min:1', 'max:50'],
            'plantillas.*' => ['string', 'distinct', 'max:64'],
            'aviso_aceptado' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['aviso_aceptado.*' => __('Tenés que aceptar el aviso: validá este plan con tu veterinario.')];
    }
}
