<?php

namespace App\Http\Requests\V1\Sanidad;

use App\Rules\Catalogo;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** TareaInput (manual o reprogramar). */
class TareaRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('tareas.gestionar', $this->route('establecimiento'));

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
            'titulo' => [$req, 'string', 'min:2', 'max:120'],
            'tipo_evento' => [$req, Rule::exists('tipos_evento', 'codigo')],
            'enfermedad' => ['sometimes', 'nullable', Catalogo::existe('enfermedades', $est->id)],
            'producto_id' => ['sometimes', 'nullable', Reglas::UUID, Rule::exists('productos_sanitarios', 'id')->where('establecimiento_id', $est->id)],
            'alcance' => ['sometimes', 'array'],
            'alcance.lote_ids.*' => [Rule::exists('lotes', 'id')->where('establecimiento_id', $est->id)],
            'alcance.potrero_ids.*' => [Rule::exists('potreros', 'id')->where('establecimiento_id', $est->id)],
            'alcance.animal_ids.*' => [Rule::exists('animales', 'id')->where('establecimiento_id', $est->id)],
            'alcance.categorias.*' => [Rule::exists('categorias', 'codigo')],
            'fecha_desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'vence_en' => [$req, 'date_format:Y-m-d'],
        ];
    }
}
