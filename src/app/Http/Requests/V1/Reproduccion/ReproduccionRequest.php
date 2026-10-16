<?php

namespace App\Http\Requests\V1\Reproduccion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Query de `GET …/reproduccion/tablero` y `…/reproduccion/partos`. La autorización va antes de validar:
 * un no-miembro recibe 404 aunque los filtros sean inválidos.
 */
class ReproduccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('reproduccion.ver', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'especie' => ['sometimes', 'nullable', 'string', 'exists:especies,codigo'],
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'vencidos' => ['sometimes', 'nullable', 'in:true,false,1,0'],
        ];
    }

    /** @return array{desde: ?string, hasta: ?string, especie: ?string, vencidos: ?bool} */
    public function filtros(): array
    {
        $v = $this->validated();

        return [
            'desde' => $v['desde'] ?? null,
            'hasta' => $v['hasta'] ?? null,
            'especie' => $v['especie'] ?? null,
            'vencidos' => isset($v['vencidos']) ? filter_var($v['vencidos'], FILTER_VALIDATE_BOOLEAN) : null,
        ];
    }
}
