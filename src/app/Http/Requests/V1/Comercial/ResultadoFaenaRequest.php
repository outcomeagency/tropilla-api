<?php

namespace App\Http\Requests\V1\Comercial;

use App\Domain\Comercial\Faena\Faena;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Alta (POST) o edición (PATCH: venta/egreso, liquidación, documentos) del resultado de faena (TPA-67). Autoriza antes de validar. */
class ResultadoFaenaRequest extends FormRequest
{
    public const KG = 'regex:/^\d{1,5}([.,]\d{1,3})?$/';

    public const IMPORTE = 'regex:/^-?\d{1,13}(\.\d{1,4})?$/';

    public function authorize(): bool
    {
        Gate::authorize('comercial.precios', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public static function liquidacion(): array
    {
        return [
            'liquidacion' => ['nullable', 'array'],
            'liquidacion.numero' => ['nullable', 'string', 'max:60'],
            'liquidacion.fecha' => ['nullable', 'date_format:Y-m-d'],
            'liquidacion.emisor' => ['nullable', 'string', 'max:200'],
            'liquidacion.kg' => ['nullable', 'string', self::KG],
            'liquidacion.precio_kg' => ['nullable', 'string', self::IMPORTE],
            'liquidacion.bruto' => ['nullable', 'string', self::IMPORTE],
            'liquidacion.neto' => ['nullable', 'string', self::IMPORTE],
            'liquidacion.moneda' => ['nullable', 'in:ARS,USD'],
            'liquidacion.deducciones' => ['nullable', 'array', 'max:30'],
            'liquidacion.deducciones.*.concepto' => ['required', 'string', 'max:120'],
            'liquidacion.deducciones.*.importe' => ['required', 'string', self::IMPORTE],
            'liquidacion_documento_id' => ['nullable', Reglas::UUID],
            'factura_documento_id' => ['nullable', Reglas::UUID],
            'operacion_id' => ['nullable', Reglas::UUID],
            'egreso_evento_id' => ['nullable', Reglas::UUID],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function reglas(): array
    {
        return [
            'id' => ['sometimes', Reglas::UUID],
            'frigorifico' => ['required', 'string', 'max:200'],
            'frigorifico_cuit' => ['nullable', 'string', 'max:20'],
            'faenador_numero' => ['nullable', 'string', 'max:40'],
            'fecha_faena' => ['required', 'date_format:Y-m-d'],
            'tropa_numero' => ['nullable', 'string', 'max:40'],
            'romaneo_numero' => ['nullable', 'string', 'max:40'],
            'dte_numero' => ['nullable', 'string', 'max:40'],
            'cabezas_declaradas' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'tipificador' => ['nullable', 'string', 'max:200'],
            'origen' => ['required', Rule::in(Faena::ORIGENES)],
            'documento_id' => ['nullable', Reglas::UUID],
            'reses' => ['required', 'array', 'min:1', 'max:2000'],
            'reses.*.garron' => ['required', 'string', 'max:40'],
            'reses.*.identificacion' => ['nullable', 'string', 'max:100'],
            'reses.*.animal_id' => ['nullable', Reglas::UUID],
            'reses.*.media_a_kg' => ['nullable', 'string', self::KG],
            'reses.*.media_b_kg' => ['nullable', 'string', self::KG],
            'reses.*.peso_kg' => ['nullable', 'string', self::KG],
            'reses.*.categoria' => ['nullable', 'string', 'max:60'],
            'reses.*.tipificacion' => ['nullable', 'string', 'max:60'],
            'reses.*.decomiso' => ['nullable', 'string', 'max:200'],
            'reses.*.observaciones' => ['nullable', 'string', 'max:300'],
            'confirmar_advertencias' => ['sometimes', 'boolean'],
            ...self::liquidacion(),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->isMethod('patch') ? [...self::liquidacion(), 'row_version' => ['required', 'integer', 'min:1']] : self::reglas();
    }

    /** Coma decimal → punto (las planillas argentinas usan coma). */
    protected function prepareForValidation(): void
    {
        $reses = $this->input('reses');
        if (is_array($reses)) {
            foreach ($reses as $i => $r) {
                foreach (['media_a_kg', 'media_b_kg', 'peso_kg'] as $k) {
                    if (is_array($r) && isset($r[$k]) && is_string($r[$k])) {
                        $reses[$i][$k] = str_replace(',', '.', trim($r[$k]));
                    }
                }
            }
            $this->merge(['reses' => $reses]);
        }
        $liq = $this->input('liquidacion');
        if (is_array($liq) && isset($liq['kg']) && is_string($liq['kg'])) {
            $liq['kg'] = str_replace(',', '.', trim($liq['kg']));
            $this->merge(['liquidacion' => $liq]);
        }
    }
}
