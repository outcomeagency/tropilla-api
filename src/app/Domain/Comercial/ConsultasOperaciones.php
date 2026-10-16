<?php

namespace App\Domain\Comercial;

use App\Domain\Integridad\Confianza;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Schema `Operacion` (privado: solo `comercial.precios`). */
final class ConsultasOperaciones
{
    /** @return array<string, mixed> */
    public static function operacion(object $o): array
    {
        $v = DB::table('operacion_versiones')->where('operacion_id', $o->id)->where('version', $o->version_vigente)->first();
        $ultimaConDatos = DB::table('operacion_versiones')->where('operacion_id', $o->id)->where('accion', '<>', 'anulacion')->orderByDesc('version')->first();
        $ref = $o->precio_referencia_id ? DB::table('precios_referencia')->where('id', $o->precio_referencia_id)->first() : null;

        return [
            'id' => $o->id, 'tipo' => $o->tipo, 'estado' => $o->estado, 'version' => (int) $o->version_vigente,
            'fecha' => Carbon::parse($o->fecha)->toDateString(), 'especie' => $o->especie_codigo, 'categoria' => $o->categoria_codigo, 'cantidad' => (int) $o->cantidad,
            'contraparte' => $o->contraparte, 'destino' => $o->destino, 'modalidad' => $o->modalidad,
            'precio_kg' => Formato::decimal($o->precio_kg), 'moneda' => $o->moneda, 'neto_total' => Formato::decimal($o->neto_total),
            'neto_kg_vivo' => Formato::decimal($o->neto_kg_vivo), 'precio_referencia' => $ref ? self::referencia($ref) : null,
            'diferencial_pct' => Formato::decimal($o->diferencial_pct),
            'trato' => (object) json_decode($ultimaConDatos->trato, true), 'liquidacion' => (object) json_decode($ultimaConDatos->liquidacion, true),
            'trazabilidad' => (object) (json_decode($ultimaConDatos->trazabilidad, true) ?: []),
            'animal_ids' => DB::table('operacion_animales')->where('operacion_id', $o->id)->orderBy('animal_id')->pluck('animal_id')->all(),
            'oferta_id' => $o->oferta_id,
            'evento_id' => $o->evento_raiz_id, 'contrato_id' => $o->contrato_id, 'documento_id' => $o->documento_id,
            'huella' => $v->hash, 'confianza' => Confianza::deRegistro((int) $v->registro_integridad_id),
        ];
    }

    /** @return array<string, mixed> PrecioReferencia */
    public static function referencia(object $r): array
    {
        return ['id' => $r->id, 'descripcion' => $r->descripcion, 'especie' => $r->especie_codigo, 'categoria' => $r->categoria_codigo, 'modalidad' => $r->modalidad,
            'precio_kg' => Formato::decimal($r->precio_kg), 'moneda' => $r->moneda, 'fecha' => Carbon::parse($r->fecha)->toDateString(), 'fuente' => $r->fuente];
    }
}
