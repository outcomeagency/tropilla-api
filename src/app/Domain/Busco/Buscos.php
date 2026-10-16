<?php

namespace App\Domain\Busco;

use App\Casts\PgTextArray;
use App\Domain\Establecimientos\Data\Provincias;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Presentación de «Busco» (TPA-58). El comprador sale anónimo (tipo y país) salvo que elija mostrar su nombre. */
final class Buscos
{
    /** @return array<string, mixed> Busco */
    public static function busco(object $b, bool $propio = false): array
    {
        $c = DB::table('usuarios as u')->leftJoin('compradores as c', 'c.usuario_id', '=', 'u.id')->where('u.id', $b->comprador_id)->first(['u.nombre', 'c.empresa', 'c.tipo', 'c.pais']);
        $zona = json_decode($b->zona, true) ?: [];

        return [
            'id' => $b->id, 'especie' => $b->especie_codigo, 'categorias' => PgTextArray::parse($b->categorias), 'razas' => PgTextArray::parse($b->razas),
            'cantidad' => (int) $b->cantidad, 'peso_objetivo_kg' => Formato::decimal($b->peso_objetivo_kg),
            'zona' => (object) [...$zona, 'provincias_nombres' => array_map(fn ($p) => Provincias::TODAS[$p] ?? $p, (array) ($zona['provincias'] ?? []))],
            'plazo_hasta' => $b->plazo_hasta, 'mensaje' => $b->mensaje, 'mostrar_nombre' => (bool) $b->mostrar_nombre,
            'comprador' => $c ? array_filter(['tipo' => $c->tipo, 'pais' => $c->pais, 'nombre' => ($b->mostrar_nombre || $propio) ? $c->nombre : null,
                'empresa' => ($b->mostrar_nombre || $propio) ? $c->empresa : null], fn ($v) => $v !== null) : null,
            'estado' => $b->estado,
            'respuestas_count' => $propio ? DB::table('busco_respuestas')->where('busco_id', $b->id)->count() : null,
            'created_at' => Formato::fechaHora(Carbon::parse($b->created_at)),
        ];
    }

    /** @return array<string, mixed> BuscoRespuesta */
    public static function respuesta(object $r): array
    {
        $est = DB::table('establecimientos as e')->leftJoin('perfiles_publicos as p', fn ($j) => $j->on('p.establecimiento_id', '=', 'e.id')->where('p.publicado', true))
            ->where('e.id', $r->establecimiento_id)->first(['e.id', 'e.nombre', 'p.slug']);
        $o = $r->oferta_id ? DB::table('ofertas')->where('id', $r->oferta_id)->first(['codigo_publico', 'estado']) : null;

        return ['id' => $r->id, 'busco_id' => $r->busco_id, 'establecimiento' => ['id' => $est->id, 'nombre' => $est->nombre, 'slug' => $est->slug],
            'mensaje' => $r->mensaje, 'oferta' => $o ? ['codigo' => $o->codigo_publico, 'estado' => $o->estado] : null,
            'leida_en' => $r->leida_en ? Formato::fechaHora(Carbon::parse($r->leida_en)) : null, 'created_at' => Formato::fechaHora(Carbon::parse($r->created_at))];
    }
}
