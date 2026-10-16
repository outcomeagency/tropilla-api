<?php

namespace App\Http\Requests\V1\Sanidad;

use App\Domain\Sanidad\Models\ProductoSanitario;
use App\Rules\Catalogo;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * ProductoSanitarioInput (POST y PATCH). `dosis_declarada` es texto libre del usuario (TROPILLA no prescribe);
 * especies y enfermedades contra catálogos (datos).
 */
class ProductoSanitarioRequest extends FormRequest
{
    /** Autoriza antes de validar: un no-miembro recibe 404 aunque el cuerpo sea inválido. */
    public function authorize(): bool
    {
        Gate::authorize('maestros.editar', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $patch = $this->isMethod('patch');
        $req = $patch ? 'sometimes' : 'required';
        $est = $this->route('establecimiento');

        return [
            'id' => $patch ? ['prohibited'] : ['sometimes', 'nullable', Reglas::UUID],
            'row_version' => $patch ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'nombre_comercial' => [$req, 'string', 'min:2', 'max:120'],
            'principio_activo' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tipo' => [$req, Rule::in(ProductoSanitario::TIPOS)],
            'via' => ['sometimes', 'nullable', Rule::in(ProductoSanitario::VIAS)],
            'dosis_declarada' => ['sometimes', 'nullable', 'string', 'max:200'],
            'retiro_carne_dias' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
            'retiro_leche_dias' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
            'especies' => ['sometimes', 'array'],
            'especies.*' => ['distinct', Rule::exists('especies', 'codigo')],
            'enfermedades' => ['sometimes', 'array'],
            'enfermedades.*' => ['distinct', Catalogo::existe('enfermedades', is_object($est) ? $est->id : null)],
            'notas' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sugerido_codigo' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
