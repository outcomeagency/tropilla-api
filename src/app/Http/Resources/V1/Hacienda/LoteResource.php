<?php

namespace App\Http\Resources\V1\Hacienda;

use App\Domain\Hacienda\Models\Lote;
use App\Domain\Sanidad\Retiros;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Schema `Lote`: maestro + composición y potrero derivados de eventos (`lotes_estado`, `lote_conteos`).
 *
 * @property Lote $resource
 */
class LoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $l = $this->resource;
        $e = DB::table('lotes_estado as le')->leftJoin('potreros as p', 'p.id', '=', 'le.potrero_id')
            ->where('le.lote_id', $l->id)->first(['le.*', 'p.nombre as potrero_nombre']);
        $conteo = DB::table('lote_conteos')->where('lote_id', $l->id)->orderBy('categoria_codigo')->pluck('cabezas', 'categoria_codigo')->map(fn ($n) => (int) $n)->all();

        // Retiro (TPA-35): el mayor entre los individuales del lote y las aplicaciones al lote anónimo.
        $ret = Retiros::deLote($l->id);
        $ind = DB::table('animales_estado')->where('lote_id', $l->id)->where('estado', 'activo')
            ->selectRaw('max(retiro_carne_hasta) as carne, max(retiro_leche_hasta) as leche')->first();
        $max = fn (?string $a, ?string $b) => $a === null ? $b : ($b === null ? $a : max($a, $b));

        return [
            'id' => $l->id,
            'nombre' => $l->nombre,
            'especie' => $l->especie_codigo,
            'proposito' => $l->proposito,
            'anonimo' => (bool) $l->anonimo,
            'notas' => $l->notas,
            'potrero' => $e?->potrero_id ? ['id' => $e->potrero_id, 'nombre' => $e->potrero_nombre] : null,
            'cabezas_individuales' => (int) ($e->cabezas_individuales ?? 0),
            'cabezas_anonimas' => (int) ($e->cabezas_anonimas ?? 0),
            'conteo' => (object) $conteo,
            'peso_promedio_kg' => Formato::decimal($e->peso_promedio_kg ?? null),
            'peso_promedio_fecha' => $e->peso_promedio_fecha ?? null,
            'gdp_promedio_kg_dia' => Formato::decimal($e->gdp_promedio_kg_dia ?? null),
            'retiro_carne_hasta' => $max($ret['carne'], $ind->carne),
            'retiro_leche_hasta' => $max($ret['leche'], $ind->leche),
            'row_version' => (int) $l->row_version,
            'archivado_en' => Formato::fechaHora($l->archivado_en),
        ];
    }
}
