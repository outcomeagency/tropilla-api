<?php

namespace App\Http\Requests\V1\Sanidad;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Query de `…/sanidad/retiros` y `…/sanidad/reactores` (autoriza antes de validar: ajeno → 404). */
class SanidadListaRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('sanidad.ver', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['especie' => ['sometimes', 'nullable', 'exists:especies,codigo']];
    }
}
