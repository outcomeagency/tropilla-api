<?php

namespace App\Http\Requests\V1\Sanidad;

use App\Domain\Sanidad\Oficial\SanidadOficial;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Alta (POST) o edición (PATCH, con `row_version`) del plan de saneamiento (TPA-66). */
class PlanSaneamientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('sanidad.crear', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $alta = $this->isMethod('post');
        $req = $alta ? 'required' : 'sometimes';

        return [
            'id' => ['sometimes', Reglas::UUID],
            'enfermedad' => [$alta ? 'required' : 'prohibited', Rule::in(SanidadOficial::ENFERMEDADES)],
            'inicio' => [$req, 'date_format:Y-m-d'],
            'proximo_muestreo' => ['nullable', 'date_format:Y-m-d'],
            'veterinario_nombre' => ['nullable', 'string', 'max:200'],
            'veterinario_matricula' => ['nullable', 'string', 'max:60'],
            'protocolo_id' => ['nullable', Reglas::UUID],
            'documento_id' => ['nullable', Reglas::UUID],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'acciones' => ['sometimes', 'array', 'max:100'],
            'acciones.*.descripcion' => ['required', 'string', 'max:300'],
            'acciones.*.responsable' => ['nullable', 'string', 'max:120'],
            'acciones.*.fecha_objetivo' => ['nullable', 'date_format:Y-m-d'],
            'acciones.*.hecha_el' => ['nullable', 'date_format:Y-m-d'],
            'estado' => [$alta ? 'prohibited' : 'sometimes', 'in:activo,cumplido,anulado'],
            'cerrado_el' => [$alta ? 'prohibited' : 'nullable', 'date_format:Y-m-d'],
            'row_version' => [$alta ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
