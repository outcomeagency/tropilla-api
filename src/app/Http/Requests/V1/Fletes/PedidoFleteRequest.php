<?php

namespace App\Http\Requests\V1\Fletes;

use App\Domain\Establecimientos\Data\Provincias;
use App\Domain\Fletes\Fletes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * FletePedidoInput (TPA-73). La zona de carga es el departamento (por defecto, el del establecimiento) y el destino es
 * provincia + localidad: no se piden coordenadas ni direcciones. Exige el consentimiento para compartir datos mínimos.
 */
class PedidoFleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('fletes.gestionar', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'origen_departamento_codigo' => ['sometimes', 'nullable', 'string', 'regex:/^\d{5}$/'],
            'destino_provincia_codigo' => ['required', 'in:'.implode(',', array_keys(Provincias::TODAS))],
            'destino_localidad' => ['required', 'string', 'max:120'],
            'motivo' => ['required', 'in:'.implode(',', array_keys(Fletes::MOTIVOS))],
            'fecha_desde' => ['required', 'date_format:Y-m-d'],
            'fecha_hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'especie_codigo' => ['required', 'in:BOV,BUB'],
            'categoria' => ['nullable', 'string', 'max:80'],
            'cabezas' => ['required', 'integer', 'min:1', 'max:2000'],
            'kg_promedio' => ['nullable', 'integer', 'min:20', 'max:1500'],
            'jaula_preferida' => ['nullable', 'in:'.implode(',', array_keys(Fletes::JAULAS))],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'telefono_contacto' => ['required', 'string', 'regex:/^[0-9 +()\-]{6,30}$/'],
            'consentimiento' => ['required', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $tz = $this->route('establecimiento')->zona_horaria;
            $desde = Carbon::parse((string) $this->input('fecha_desde'), $tz);
            if ($desde->lt(Carbon::today($tz))) {
                $v->errors()->add('fecha_desde', 'La fecha no puede ser anterior a hoy.');
            }
            if ($desde->diffInDays(Carbon::parse((string) $this->input('fecha_hasta'), $tz)) > 60) {
                $v->errors()->add('fecha_hasta', 'La ventana de fechas puede ser de hasta 60 días.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['destino_localidad' => 'localidad de destino', 'telefono_contacto' => 'teléfono de contacto', 'fecha_desde' => 'fecha desde', 'fecha_hasta' => 'fecha hasta'];
    }
}
