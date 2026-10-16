<?php

namespace App\Http\Requests\V1\Sanidad;

use App\Rules\Catalogo;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PlanItemInput (POST y PATCH). `disparador`: `fecha_fija {fecha MM-DD}` · `ventana {desde, hasta MM-DD | meses[]}` ·
 * `relativo {evento: nacimiento, dias | semanas[]}` · `periodico {cada_dias, desde?}` · `manual`.
 */
class PlanItemRequest extends FormRequest
{
    private const MMDD = 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/';

    public function authorize(): bool
    {
        Gate::authorize('plan.editar', $this->route('establecimiento'));

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
            'nombre' => [$req, 'string', 'min:2', 'max:120'],
            'especie' => ['sometimes', 'nullable', Rule::exists('especies', 'codigo')],
            'tipo_evento' => [$req, Rule::exists('tipos_evento', 'codigo')->where('modulo', 'sanidad')],
            'enfermedad' => ['sometimes', 'nullable', Catalogo::existe('enfermedades', $est->id)],
            'producto_id' => ['sometimes', 'nullable', Reglas::UUID, Rule::exists('productos_sanitarios', 'id')->where('establecimiento_id', $est->id)],
            'alcance' => ['sometimes', 'array'],
            'alcance.categorias' => ['sometimes', 'array'], 'alcance.categorias.*' => [Rule::exists('categorias', 'codigo')],
            'alcance.lote_ids' => ['sometimes', 'array'], 'alcance.lote_ids.*' => [Rule::exists('lotes', 'id')->where('establecimiento_id', $est->id)],
            'alcance.potrero_ids' => ['sometimes', 'array'], 'alcance.potrero_ids.*' => [Rule::exists('potreros', 'id')->where('establecimiento_id', $est->id)],
            'alcance.animal_ids' => ['sometimes', 'array'], 'alcance.animal_ids.*' => [Rule::exists('animales', 'id')->where('establecimiento_id', $est->id)],
            'disparador' => [$req, 'array'],
            'disparador.tipo' => [$req, 'in:fecha_fija,ventana,relativo,periodico,manual'],
            'disparador.fecha' => ['sometimes', 'nullable', self::MMDD],
            'disparador.desde' => ['sometimes', 'nullable', 'string', function ($a, $v, $fail) {
                $periodico = ($this->input('disparador.tipo') === 'periodico');
                if ($v !== null && ! preg_match($periodico ? '/^\d{4}-\d{2}-\d{2}$/' : '/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', (string) $v)) {
                    $fail($periodico ? __('Fecha AAAA-MM-DD.') : __('Formato MM-DD.'));
                }
            }],
            'disparador.hasta' => ['sometimes', 'nullable', self::MMDD],
            'disparador.meses' => ['sometimes', 'array'], 'disparador.meses.*' => ['integer', 'between:1,12'],
            'disparador.evento' => ['required_if:disparador.tipo,relativo', 'in:nacimiento'],
            'disparador.dias' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'disparador.semanas' => ['sometimes', 'array'], 'disparador.semanas.*' => ['integer', 'min:0', 'max:520'],
            'disparador.cada_dias' => ['required_if:disparador.tipo,periodico', 'integer', 'min:1', 'max:3650'],
            'repeticion' => ['sometimes', 'nullable', 'array'],
            'condicion' => ['sometimes', 'nullable', 'array'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    /** Campos del modelo (la API dice `especie`; la columna es `especie_codigo`). @return array<string, mixed> */
    public function datos(): array
    {
        $d = $this->validated();
        if (array_key_exists('especie', $d)) {
            $d['especie_codigo'] = $d['especie'];
            unset($d['especie']);
        }

        return $d;
    }
}
