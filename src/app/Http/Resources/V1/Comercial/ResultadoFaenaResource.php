<?php

namespace App\Http\Resources\V1\Comercial;

use App\Domain\Comercial\Faena\Conciliacion;
use App\Domain\Comercial\Faena\Faena;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `ResultadoFaena` (TPA-67). Separa lo **oficial** (romaneo del frigorífico y su liquidación, como figuran) de la
 * **conciliación** (cálculo de TROPILLA). Privado: solo `comercial.precios`.
 */
final class ResultadoFaenaResource
{
    /** @return array<string, mixed> */
    public static function make(object $r, bool $detalle = false): array
    {
        $tot = DB::table('faena_reses')->where('resultado_id', $r->id)->selectRaw('count(*) n, coalesce(sum(peso_kg), 0) kg, count(animal_id) asociadas')->first();
        $fh = fn (?string $t) => $t === null ? null : Formato::fechaHora(Carbon::parse($t));
        $out = [
            'id' => $r->id, 'estado' => $r->estado, 'origen' => $r->origen, 'origen_leyenda' => Faena::leyendaOrigen($r->origen),
            'operacion_id' => $r->operacion_id, 'egreso_evento_id' => $r->egreso_evento_id,
            'documento_id' => $r->documento_id, 'liquidacion_documento_id' => $r->liquidacion_documento_id, 'factura_documento_id' => $r->factura_documento_id,
            'borrador_id' => $r->borrador_id,
            'oficial' => [
                'leyenda' => __(Faena::LEYENDA_OFICIAL),
                'frigorifico' => $r->frigorifico, 'frigorifico_cuit' => $r->frigorifico_cuit, 'faenador_numero' => $r->faenador_numero,
                'fecha_faena' => (string) $r->fecha_faena, 'tropa_numero' => $r->tropa_numero, 'romaneo_numero' => $r->romaneo_numero,
                'dte_numero' => $r->dte_numero, 'cabezas_declaradas' => $r->cabezas_declaradas === null ? null : (int) $r->cabezas_declaradas,
                'tipificador' => $r->tipificador,
                'totales' => ['reses' => (int) $tot->n, 'kg' => Formato::decimal($tot->kg) ?? '0', 'asociadas' => (int) $tot->asociadas],
            ],
            'liquidacion' => $r->liquidacion ? json_decode($r->liquidacion, true) : null,
            'observaciones' => $r->observaciones, 'row_version' => (int) $r->row_version, 'creado_por' => $r->creado_por,
            'created_at' => $fh($r->created_at), 'updated_at' => $fh($r->updated_at), 'anulado_en' => $fh($r->anulado_en), 'anulado_motivo' => $r->anulado_motivo,
        ];
        if ($detalle) {
            $out['oficial']['reses'] = DB::table('faena_reses')->where('resultado_id', $r->id)->orderBy('orden')->get()->map(fn ($x) => [
                'orden' => (int) $x->orden, 'garron' => $x->garron, 'identificacion' => $x->identificacion, 'animal_id' => $x->animal_id,
                'media_a_kg' => Formato::decimal($x->media_a_kg), 'media_b_kg' => Formato::decimal($x->media_b_kg), 'peso_kg' => Formato::decimal($x->peso_kg),
                'categoria' => $x->categoria, 'tipificacion' => $x->tipificacion, 'decomiso' => $x->decomiso, 'observaciones' => $x->observaciones,
            ])->all();
            $out['conciliacion'] = Conciliacion::de($r);
        }

        return $out;
    }
}
