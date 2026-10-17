<?php

namespace App\Http\Requests\V1\Comercial;

use App\Rules\Catalogo;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** OperacionInput (alta y corrección). Decimales como string; trazabilidad declarada. Aditivos: `animal_ids`, `oferta_id`, `lote_id`, `contrato_id`, `confirmar_advertencias`. */
class OperacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('comercial.precios', $this->route('establecimiento'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $est = $this->route('establecimiento');
        $dec = ['string', Reglas::DECIMAL_POS];
        $pct = [...$dec, 'numeric', 'max:100'];
        $correccion = $this->routeIs('*.correcciones');

        return [
            'id' => $correccion ? ['prohibited'] : ['sometimes', 'nullable', Reglas::UUID],
            'motivo' => $correccion ? ['required', 'string', 'min:3', 'max:500'] : ['sometimes', 'nullable', 'string', 'max:500'],
            'tipo' => ['required', 'in:venta,compra'],
            'simulacion_id' => ['sometimes', 'nullable', Reglas::UUID, Rule::exists('simulaciones', 'id')->where('establecimiento_id', $est->id)],
            'evento_raiz_id' => ['sometimes', 'nullable', Reglas::UUID],
            'contrato_id' => ['sometimes', 'nullable', Reglas::UUID],
            'proveedor_id' => ['sometimes', 'nullable', Reglas::UUID, Rule::exists('proveedores', 'usuario_id')],
            'oferta_id' => ['sometimes', 'nullable', Reglas::UUID, Rule::exists('ofertas', 'id')->where('establecimiento_id', $est->id)],
            'lote_id' => ['sometimes', 'nullable', Reglas::UUID, Rule::exists('lotes', 'id')->where('establecimiento_id', $est->id)],
            'animal_ids' => ['sometimes', 'array', 'max:1000'], 'animal_ids.*' => [Reglas::UUID],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'especie' => ['required', Rule::exists('especies', 'codigo')],
            'categoria' => ['required', Rule::exists('categorias', 'codigo')->where('especie_codigo', $this->input('especie'))],
            'cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
            'trato' => ['required', 'array'],
            'trato.comprador' => ['sometimes', 'nullable', 'string', 'max:200'],
            'trato.destino' => ['sometimes', 'nullable', Catalogo::existe('destinos', $est->id)],
            'trato.lugar_pesada' => ['sometimes', 'nullable', 'string', 'max:200'],
            'trato.hora_pesada' => ['sometimes', 'nullable', 'date_format:H:i'],
            'trato.modalidad' => ['required', 'in:en_pie,al_gancho'],
            'trato.desbaste_pct' => ['sometimes', 'nullable', ...$pct],
            'trato.tolerancias' => ['sometimes', 'nullable', 'string', 'max:300'],
            'trato.plazo_pago_dias' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'trato.flete_a_cargo' => ['sometimes', 'nullable', 'in:vendedor,comprador'],
            'trato.comision_pct' => ['sometimes', 'nullable', ...$pct],
            'trato.intermediario' => ['sometimes', 'nullable', 'string', 'max:200'],
            'liquidacion' => ['required', 'array'],
            'liquidacion.peso_bruto_kg' => ['required', ...$dec, 'numeric', 'gt:0'],
            'liquidacion.precio_kg' => ['required', ...$dec],
            'liquidacion.desbaste_pct' => ['sometimes', 'nullable', ...$pct],
            'liquidacion.rendimiento_pct' => ['sometimes', 'nullable', ...$pct],
            'liquidacion.iva_pct' => ['sometimes', 'nullable', ...$pct],
            'liquidacion.flete' => ['sometimes', 'nullable', ...$dec],
            'liquidacion.arranque' => ['sometimes', 'nullable', ...$dec],
            'liquidacion.seguro' => ['sometimes', 'nullable', ...$dec],
            'liquidacion.comision_pct' => ['sometimes', 'nullable', ...$pct],
            'liquidacion.moneda' => ['sometimes', 'nullable', 'in:ARS,USD'],
            'trazabilidad' => ['sometimes', 'array'],
            'trazabilidad.dte_numero' => ['sometimes', 'nullable', 'string', 'max:60'],
            'trazabilidad.tropa_numero' => ['sometimes', 'nullable', 'string', 'max:60'],
            'trazabilidad.renspa_origen' => ['sometimes', 'nullable', 'string', 'max:40'],
            'trazabilidad.renspa_destino' => ['sometimes', 'nullable', 'string', 'max:40'],
            'precio_referencia_id' => ['sometimes', 'nullable', Reglas::UUID, Rule::exists('precios_referencia', 'id')->where('establecimiento_id', $est->id)],
            'generar_pdf' => ['sometimes', 'boolean'],
            'confirmar_advertencias' => ['sometimes', 'boolean'],
        ];
    }
}
