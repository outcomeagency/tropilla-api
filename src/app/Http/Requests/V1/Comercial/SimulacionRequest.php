<?php

namespace App\Http\Requests\V1\Comercial;

use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** SimulacionInput (calcular y guardar). Decimales como string canónico; al menos una modalidad. */
class SimulacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('comercial.precios', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $dec = ['string', Reglas::DECIMAL_POS];
        $pct = [...$dec, 'numeric', 'max:100'];

        return [
            'nombre' => ['sometimes', 'nullable', 'string', 'max:120'],
            'entradas' => ['required', 'array'],
            'entradas.especie' => ['sometimes', 'nullable', Rule::exists('especies', 'codigo')],
            'entradas.categoria' => ['sometimes', 'nullable', Rule::exists('categorias', 'codigo')],
            'entradas.cantidad' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'entradas.en_pie' => ['required_without:entradas.al_gancho', 'array'],
            'entradas.en_pie.peso_bruto_kg' => ['required_with:entradas.en_pie', ...$dec, 'numeric', 'gt:0'],
            'entradas.en_pie.desbaste_pct' => ['sometimes', ...$pct],
            'entradas.en_pie.precio_kg' => ['required_with:entradas.en_pie', ...$dec],
            'entradas.al_gancho' => ['required_without:entradas.en_pie', 'array'],
            'entradas.al_gancho.peso_bruto_kg' => ['required_with:entradas.al_gancho', ...$dec, 'numeric', 'gt:0'],
            'entradas.al_gancho.desbaste_pct' => ['sometimes', ...$pct],
            'entradas.al_gancho.rendimiento_pct' => ['sometimes', ...$pct, 'gt:0'],
            'entradas.al_gancho.precio_kg' => ['required_with:entradas.al_gancho', ...$dec],
            'entradas.iva_pct' => ['sometimes', ...$pct],
            'entradas.flete' => ['sometimes', ...$dec],
            'entradas.arranque' => ['sometimes', ...$dec],
            'entradas.seguro' => ['sometimes', ...$dec],
            'entradas.comision_pct' => ['sometimes', ...$pct],
            'entradas.moneda' => ['sometimes', 'in:ARS,USD'],
        ];
    }
}
