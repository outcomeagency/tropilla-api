<?php

namespace App\Http\Requests\V1\Contratos;

use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** ContratoInput (calcular, crear, nueva versión de términos). Decimales como string. */
class ContratoRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('contratos.gestionar', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $dec = ['string', Reglas::DECIMAL_POS, 'numeric'];
        $nueva = $this->routeIs('*.terminos');
        $req = $nueva ? 'sometimes' : 'required';

        return [
            'lote_id' => [$nueva ? 'sometimes' : 'required', Reglas::UUID],
            'cabezas' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'peso_vivo_estimado_kg' => [$req, ...$dec, 'gt:0', 'max:2000'],
            'rendimiento_pct' => ['sometimes', 'nullable', ...$dec, 'gt:0', 'max:100'],
            'kg_por_token' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'precio_referencia_kg' => [$req, ...$dec, 'gt:0'],
            'descuento_pct' => [$req, ...$dec, 'max:100'],
            'anticipo_pct' => [$req, ...$dec, 'max:100'],
            'fecha_entrega' => [$req, 'date_format:Y-m-d'],
            'moneda' => ['sometimes', 'in:ARS,USD'],
            'tolerancia_pct' => ['sometimes', 'nullable', ...$dec, 'max:50'],
            'toma_vencimiento_horas' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:720'],
            'motivo' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
