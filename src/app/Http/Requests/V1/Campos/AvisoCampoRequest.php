<?php

namespace App\Http\Requests\V1\Campos;

use App\Casts\PgTextArray;
use App\Domain\Campos\Campos;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * CampoAvisoInput (TPA-74). En POST todo lo obligatorio; en PATCH parcial con `row_version`. La zona se limita a las provincias
 * del piloto y el departamento debe pertenecer a la provincia (por defecto, los del establecimiento).
 */
class AvisoCampoRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('campos.gestionar', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $parcial = $this->isMethod('PATCH');
        $req = $parcial ? 'sometimes' : 'required';

        return [
            'row_version' => [$parcial ? 'required' : 'prohibited', 'integer'],
            'titulo' => [$req, 'string', 'min:5', 'max:120'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'modalidad' => [$req, Rule::in(array_keys(Campos::MODALIDADES))],
            'especies' => [$req, 'array', 'min:1', 'max:5'], 'especies.*' => ['distinct', 'exists:especies,codigo'],
            'categorias' => ['sometimes', 'array', 'max:20'], 'categorias.*' => ['distinct', 'exists:categorias,codigo'],
            'capacidad_cabezas' => [$req, 'integer', 'min:1', 'max:100000'],
            'superficie_ha' => ['sometimes', 'nullable', 'string', Reglas::DECIMAL_POS, 'numeric', 'max:1000000'],
            'temporada_desde' => [$req, 'date_format:Y-m-d'],
            'temporada_hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'plazo_minimo_meses' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            'precio_unidad' => [$req, Rule::in(array_keys(Campos::PRECIO_UNIDADES))],
            'precio_valor' => ['sometimes', 'nullable', 'string', Reglas::DECIMAL_POS, 'numeric', 'max:999999999'],
            'condiciones' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'agua' => ['sometimes', 'array'], 'agua.*' => ['distinct', Rule::in(array_keys(Campos::AGUA))],
            'alambrado' => ['sometimes', 'nullable', Rule::in(array_keys(Campos::ALAMBRADO))],
            'instalaciones' => ['sometimes', 'array'], 'instalaciones.*' => ['distinct', Rule::in(array_keys(Campos::INSTALACIONES))],
            'accesos' => ['sometimes', 'nullable', 'string', 'max:500'],
            'provincia_codigo' => ['sometimes', 'string', Rule::in(Campos::provinciasPiloto())],
            'departamento_codigo' => ['sometimes', 'string', 'regex:/^[0-9]{5}$/'],
            'localidad' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mostrar_localidad' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['provincia_codigo.in' => 'El piloto de campos funciona por ahora en Corrientes, Chaco, Formosa y Misiones.'];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $actual = $this->route('aviso') ? DB::table('campo_avisos')->where('id', strtolower((string) $this->route('aviso')))->first() : null;
            $est = $this->route('establecimiento');
            $prov = $this->input('provincia_codigo', $actual->provincia_codigo ?? $est->provincia_codigo);
            $depto = $this->input('departamento_codigo', $actual->departamento_codigo ?? $est->departamento_codigo);
            if (! in_array($prov, Campos::provinciasPiloto(), true)) {
                $v->errors()->add('provincia_codigo', $this->messages()['provincia_codigo.in']);
            } elseif (! str_starts_with((string) $depto, $prov) || (DB::table('geo_departamentos')->where('provincia_codigo', $prov)->exists()
                && ! DB::table('geo_departamentos')->where('codigo', $depto)->exists())) {
                // Con padrón de la provincia cargado se exige que exista; si no, solo formato y prefijo (como el alta de establecimiento).
                $v->errors()->add('departamento_codigo', 'El departamento no pertenece a la provincia elegida.');
            }
            $desde = $this->input('temporada_desde', $actual->temporada_desde ?? null);
            $hasta = $this->exists('temporada_hasta') ? $this->input('temporada_hasta') : ($actual->temporada_hasta ?? null);
            if ($desde && $hasta && $hasta < $desde) {
                $v->errors()->add('temporada_hasta', 'La temporada termina antes de empezar.');
            }
            $unidad = $this->input('precio_unidad', $actual->precio_unidad ?? null);
            if ($unidad !== null && $unidad !== 'a_convenir' && ($this->exists('precio_valor') ? $this->input('precio_valor') : ($actual->precio_valor ?? null)) === null) {
                $v->errors()->add('precio_valor', 'Indicá el valor o elegí «a convenir».');
            }
            $especies = $this->input('especies', $actual ? PgTextArray::parse($actual->especies) : []);
            $cats = (array) $this->input('categorias', []);
            if ($cats !== [] && DB::table('categorias')->whereIn('codigo', $cats)->whereNotIn('especie_codigo', $especies)->exists()) {
                $v->errors()->add('categorias', 'Hay categorías de una especie que el campo no recibe.');
            }
        }];
    }
}
