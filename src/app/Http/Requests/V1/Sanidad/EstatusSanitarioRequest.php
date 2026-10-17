<?php

namespace App\Http\Requests\V1\Sanidad;

use App\Domain\Sanidad\Oficial\SanidadOficial;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Estatus sanitario del establecimiento por enfermedad, con certificado y vigencia (TPA-66). */
class EstatusSanitarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('sanidad.crear', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', Reglas::UUID],
            'enfermedad' => ['required', Rule::in(SanidadOficial::ENFERMEDADES)],
            'estatus' => ['required', Rule::in(SanidadOficial::ESTATUS)],
            'origen' => ['required', Rule::in(SanidadOficial::ORIGENES)],
            'certificado_numero' => ['nullable', 'string', 'max:100'],
            'autoridad' => ['nullable', 'string', 'max:200'],
            'emitido_el' => ['nullable', 'date_format:Y-m-d'],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'vence_el' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vigente_desde'],
            'protocolo_id' => ['nullable', Reglas::UUID],
            'documento_id' => ['nullable', Reglas::UUID],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
