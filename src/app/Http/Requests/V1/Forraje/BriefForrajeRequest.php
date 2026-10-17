<?php

namespace App\Http\Requests\V1\Forraje;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** BriefForrajeInput (calcular y guardar). Todo es opcional: lo que no se manda sale del inventario, la ficha y los parámetros. */
class BriefForrajeRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('forraje.crear', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $pct = ['sometimes', 'nullable', 'numeric', 'between:0,100'];

        return [
            'nombre' => ['sometimes', 'nullable', 'string', 'max:120'],
            'horizonte_dias' => ['sometimes', 'integer', 'between:7,365'],
            'superficie_ha' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'receptividad_ev_ha' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:20'],
            'inventario' => ['sometimes', 'array', 'max:40'],
            'inventario.*.categoria' => ['required', 'string', Rule::exists('categorias', 'codigo')],
            'inventario.*.cabezas' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reservas' => ['sometimes', 'array:rollos,silo,suplemento'],
            'reservas.rollos' => ['sometimes', 'nullable', 'array:cantidad,kg_por_rollo'],
            'reservas.rollos.cantidad' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'reservas.rollos.kg_por_rollo' => ['sometimes', 'nullable', 'numeric', 'between:50,2000'],
            'reservas.silo' => ['sometimes', 'nullable', 'array:toneladas'],
            'reservas.silo.toneladas' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'reservas.suplemento' => ['sometimes', 'nullable', 'array:kg'],
            'reservas.suplemento.kg' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'supuestos' => ['sometimes', 'array:consumo_ms_kg_ev_dia,aprovechamiento_reservas_pct,incertidumbre_pct,reduccion_moderado_pct,reduccion_severo_pct,rollo_kg,ms_rollos_pct,ms_silo_pct,ms_suplemento_pct'],
            'supuestos.consumo_ms_kg_ev_dia' => ['sometimes', 'nullable', 'numeric', 'between:3,25'],
            'supuestos.aprovechamiento_reservas_pct' => [...$pct, 'gt:0'],
            'supuestos.incertidumbre_pct' => ['sometimes', 'nullable', 'numeric', 'between:0,60'],
            'supuestos.reduccion_moderado_pct' => $pct,
            'supuestos.reduccion_severo_pct' => $pct,
            'supuestos.rollo_kg' => ['sometimes', 'nullable', 'numeric', 'between:50,2000'],
            'supuestos.ms_rollos_pct' => [...$pct, 'gt:0'],
            'supuestos.ms_silo_pct' => [...$pct, 'gt:0'],
            'supuestos.ms_suplemento_pct' => [...$pct, 'gt:0'],
        ];
    }

    /** Las categorías del inventario tienen que ser de una especie del establecimiento y no repetirse. */
    public function after(): array
    {
        return [function (Validator $v) {
            $filas = $this->input('inventario');
            if (! is_array($filas) || $v->errors()->isNotEmpty()) {
                return;
            }
            $codigos = array_column($filas, 'categoria');
            if (count($codigos) !== count(array_unique($codigos))) {
                $v->errors()->add('inventario', 'Cada categoría puede aparecer una sola vez.');
            }
            $especies = $this->route('establecimiento')->especies ?? [];
            $ajenas = DB::table('categorias')->whereIn('codigo', $codigos)->whereNotIn('especie_codigo', $especies)->pluck('codigo')->all();
            if ($ajenas !== []) {
                $v->errors()->add('inventario', 'Hay categorías de una especie que el establecimiento no declara: '.implode(', ', $ajenas).'.');
            }
        }];
    }
}
