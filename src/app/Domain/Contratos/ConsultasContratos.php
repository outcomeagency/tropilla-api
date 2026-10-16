<?php

namespace App\Domain\Contratos;

use App\Domain\Integridad\Confianza;
use App\Http\Resources\V1\Publico\OfertaPublicaResource;
use App\Support\Formato;
use App\Support\Leyenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Presentación de contratos, tomas y entregas (Contrato, Toma, Entrega, ContratoPublico). */
final class ConsultasContratos
{
    /** @return array<string, mixed> Contrato. `$montos` = ve comprador y montos de las tomas (`comercial.precios`). */
    public static function contrato(object $c, bool $montos): array
    {
        $v = DB::table('contrato_versiones')->where('contrato_id', $c->id)->orderByDesc('version')->first();
        $e = DB::table('entregas')->where('contrato_id', $c->id)->first();

        return [
            'id' => $c->id, 'codigo_publico' => $c->codigo_publico, 'url_publica' => config('tropilla.web_url').'/c/'.$c->codigo_publico,
            'lote' => ['id' => $c->lote_id, 'nombre' => (string) DB::table('lotes')->where('id', $c->lote_id)->value('nombre')],
            'estado' => $c->estado, 'motivo_estado' => $c->motivo_estado, 'version' => (int) $c->version_vigente,
            'terminos' => json_decode($c->terminos_borrador, true), 'tokens_comprometidos' => (int) $c->tokens_comprometidos,
            'tokens_libres' => max(0, (int) $c->tokens_totales - (int) $c->tokens_comprometidos),
            'tomas' => DB::table('tomas')->where('contrato_id', $c->id)->orderBy('created_at')->get()->map(fn ($t) => self::toma($t, $montos))->all(),
            'versiones' => DB::table('contrato_versiones')->where('contrato_id', $c->id)->orderBy('version')->get()
                ->map(fn ($x) => ['version' => (int) $x->version, 'motivo' => $x->motivo, 'huella' => $x->hash, 'created_at' => Formato::fechaHora(Carbon::parse($x->created_at))])->all(),
            'entrega' => $e ? self::entrega($e, $montos) : null,
            'huella' => $v?->hash, 'confianza' => Confianza::deRegistro($v ? (int) $v->registro_integridad_id : null),
            'publicado_en' => $c->publicado_en ? Formato::fechaHora(Carbon::parse($c->publicado_en)) : null,
            'documento_id' => $c->documento_id,
        ];
    }

    /** @return array<string, mixed> Toma */
    public static function toma(object $t, bool $montos = true): array
    {
        $comp = ! $montos ? null : (DB::getSchemaBuilder()->hasTable('compradores')
            ? DB::table('usuarios as u')->leftJoin('compradores as c', 'c.usuario_id', '=', 'u.id')->where('u.id', $t->comprador_id)->first(['u.id', 'u.nombre', 'c.empresa'])
            : DB::table('usuarios as u')->where('u.id', $t->comprador_id)->selectRaw('u.id, u.nombre, null as empresa')->first());

        return [
            'id' => $t->id, 'contrato_id' => $t->contrato_id,
            'comprador' => $comp ? ['id' => $comp->id, 'nombre' => $comp->nombre, 'empresa' => $comp->empresa] : null,
            'tokens' => (int) $t->tokens, 'tokens_vigentes' => (int) $t->tokens_vigentes, 'kg' => (int) $t->kg,
            'precio_especial_kg' => $montos ? Formato::decimal($t->precio_especial_kg) : null,
            'anticipo_monto' => $montos ? Formato::decimal($t->anticipo_monto) : null,
            'anticipo_a_devolver' => $montos ? Formato::decimal($t->anticipo_a_devolver) : null,
            'estado' => $t->estado, 'vence_en' => $t->vence_en ? Formato::fechaHora(Carbon::parse($t->vence_en)) : null,
            'movimientos' => DB::table('toma_movimientos')->where('toma_id', $t->id)->orderBy('created_at')->orderBy('id')->get()
                ->map(fn ($m) => ['tipo' => $m->tipo, 'datos' => $montos ? (object) json_decode($m->datos, true) : (object) [], 'rol_actor' => $m->rol_actor,
                    'huella' => $m->hash, 'created_at' => Formato::fechaHora(Carbon::parse($m->created_at))])->all(),
            'created_at' => Formato::fechaHora(Carbon::parse($t->created_at)),
        ];
    }

    /** @return array<string, mixed> Entrega (la liquidación por toma solo con montos; un comprador ve solo su línea) */
    public static function entrega(object $e, bool $montos, ?string $soloComprador = null): array
    {
        $liq = json_decode($e->liquidacion, true);
        if ($soloComprador) {
            $liq = array_values(array_filter($liq, fn ($l) => $l['comprador_id'] === $soloComprador));
        }

        return [
            'cabezas_entregadas' => (int) $e->cabezas_entregadas, 'peso_vivo_total_kg' => Formato::decimal($e->peso_vivo_total_kg),
            'rendimiento_real_pct' => Formato::decimal($e->rendimiento_real_pct), 'kg_gancho_reales' => (int) $e->kg_gancho_reales,
            'factor' => Formato::decimal($e->factor), 'dentro_tolerancia' => (bool) $e->dentro_tolerancia, 'estado' => $e->estado,
            'liquidacion' => $montos || $soloComprador ? array_map(fn ($l) => array_diff_key($l, ['comprador_id' => 1]), $liq) : [],
            'confirmaciones' => json_decode($e->confirmaciones, true), 'documento_id' => $e->documento_id,
        ];
    }

    /** @return array<string, mixed> ContratoPublico. Precio especial y anticipo solo con sesión de comprador (excepción explícita). */
    public static function publico(object $c, bool $conPrecios, string $locale): array
    {
        $est = DB::table('establecimientos')->where('id', $c->establecimiento_id)->first();
        $perfil = DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->where('publicado', true)->first();
        $t = json_decode($c->terminos_borrador, true);
        $v = DB::table('contrato_versiones')->where('contrato_id', $c->id)->orderByDesc('version')->first();

        return [
            'codigo' => $c->codigo_publico,
            'establecimiento' => ['slug' => (string) ($perfil?->slug ?? ''), 'nombre' => $est->nombre, 'zona' => OfertaPublicaResource::zona($est, $perfil)],
            'especie' => $c->especie_codigo, 'categoria' => $c->categoria_codigo, 'cabezas' => (int) $c->cabezas,
            'kg_ofrecidos' => (int) $c->tokens_totales * (int) $c->kg_por_token,
            'kg_libres' => ((int) $c->tokens_totales - (int) $c->tokens_comprometidos) * (int) $c->kg_por_token,
            'tokens_totales' => (int) $c->tokens_totales, 'tokens_libres' => (int) $c->tokens_totales - (int) $c->tokens_comprometidos,
            'kg_por_token' => (int) $c->kg_por_token, 'fecha_entrega' => Carbon::parse($c->fecha_entrega)->toDateString(), 'estado' => $c->estado,
            'precio_especial_kg' => $conPrecios ? $t['precio_especial_kg'] : null, 'descuento_pct' => $conPrecios ? $t['descuento_pct'] : null,
            'anticipo_pct' => $conPrecios ? $t['anticipo_pct'] : null, 'anticipo_por_token' => $conPrecios ? $t['anticipo_por_token'] : null,
            'moneda' => $conPrecios ? $t['moneda'] : null,
            'huella' => (string) $v?->hash, 'verificacion_url' => config('tropilla.web_url').'/v/'.$v?->hash, 'leyenda' => Leyenda::texto($locale),
        ];
    }
}
