<?php

namespace App\Http\Requests\V1\Sanidad;

use App\Domain\Sanidad\Oficial\SanidadOficial;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Alta de un protocolo de brucelosis/TBC con resultados por animal (TPA-66). Autoriza antes de validar (ajeno → 404). */
class ProtocoloRequest extends FormRequest
{
    public const LECTURA = 'regex:/^(0|[1-9][0-9]{0,2})(\.[1-9])?$/';

    public function authorize(): bool
    {
        Gate::authorize('sanidad.crear', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public static function reglas(): array
    {
        return [
            'id' => ['sometimes', Reglas::UUID],
            'enfermedad' => ['required', Rule::in(SanidadOficial::ENFERMEDADES)],
            'prueba_codigo' => ['required', 'string', 'exists:tipos_prueba,codigo'],
            'motivo' => ['required', Rule::in(SanidadOficial::MOTIVOS)],
            'modalidad' => ['nullable', 'in:total,muestreo'],
            'stock_base' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'fecha_muestreo' => ['required', 'date_format:Y-m-d'],
            'fecha_resultado' => ['nullable', 'date_format:Y-m-d'],
            'laboratorio' => ['nullable', 'string', 'max:200'],
            'protocolo_numero' => ['nullable', 'string', 'max:100'],
            'veterinario_nombre' => ['nullable', 'string', 'max:200'],
            'veterinario_matricula' => ['nullable', 'string', 'max:60'],
            'vence_el' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_muestreo'],
            'origen' => ['required', Rule::in(SanidadOficial::ORIGENES)],
            'documento_id' => ['nullable', Reglas::UUID],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'resultados' => ['required', 'array', 'min:1', 'max:5000'],
            'resultados.*.animal_id' => ['nullable', Reglas::UUID],
            'resultados.*.identificacion' => ['nullable', 'string', 'max:100'],
            'resultados.*.categoria_codigo' => ['nullable', 'string', 'exists:categorias,codigo'],
            'resultados.*.muestra' => ['nullable', 'string', 'max:60'],
            'resultados.*.resultado' => ['required', Rule::in(SanidadOficial::RESULTADOS)],
            'resultados.*.lectura_0h_mm' => ['nullable', 'string', self::LECTURA, 'required_with:resultados.*.lectura_72h_mm'],
            'resultados.*.lectura_72h_mm' => ['nullable', 'string', self::LECTURA, 'required_with:resultados.*.lectura_0h_mm'],
            'resultados.*.observaciones' => ['nullable', 'string', 'max:500'],
            'confirmar_advertencias' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::reglas();
    }
}
