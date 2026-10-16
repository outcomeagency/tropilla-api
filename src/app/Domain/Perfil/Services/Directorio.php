<?php

namespace App\Domain\Perfil\Services;

use App\Casts\PgTextArray;
use App\Domain\Catalogos\Catalogos;
use App\Domain\Documentos\Fotos;
use App\Domain\Establecimientos\Data\Provincias;
use App\Support\Formato;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Directorio público (TPA-54): **solo** perfiles publicados con el directorio activado (opt-in, D16). Se consulta en
 * vivo (MVP; la proyección `directorio_entradas` de modelo-datos §11 queda para cuando el volumen lo pida).
 * Orden por última actividad y completitud, **nunca por pago**.
 */
final class Directorio
{
    /** @param array<string, mixed> $f filtros q, provincia, departamento, especie, raza, categoria, con_oferta, con_contrato */
    public static function consulta(array $f): Builder
    {
        $q = DB::table('perfiles_publicos as p')->join('establecimientos as e', 'e.id', '=', 'p.establecimiento_id')
            ->leftJoin('geo_departamentos as d', 'd.codigo', '=', 'e.departamento_codigo')
            ->where('p.publicado', true)->where('p.en_directorio', true)->whereNull('e.archivado_en')
            ->select('p.*', 'e.nombre', 'e.provincia_codigo', 'e.departamento_codigo', 'e.especies', 'd.nombre as departamento_nombre')
            ->selectRaw('GREATEST(p.updated_at, (SELECT max(registrado_en) FROM eventos ev WHERE ev.establecimiento_id = e.id)) AS ultima_actividad')
            ->selectRaw("(SELECT count(*) FROM ofertas o WHERE o.establecimiento_id = e.id AND o.estado IN ('publicada','reservada')) AS ofertas_activas");

        if (! empty($f['q'])) {
            $texto = '%'.mb_strtolower((string) $f['q']).'%';
            $q->where(fn ($w) => $w->whereRaw('unaccent(lower(e.nombre)) LIKE unaccent(?)', [$texto])
                ->orWhereRaw('unaccent(lower(d.nombre)) LIKE unaccent(?)', [$texto])
                ->orWhereRaw('unaccent(lower(p.textos::text)) LIKE unaccent(?)', [$texto]));
        }
        foreach (['provincia' => 'e.provincia_codigo', 'departamento' => 'e.departamento_codigo'] as $k => $col) {
            if (! empty($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        if (! empty($f['especie'])) {
            $q->whereRaw('? = ANY(e.especies)', [$f['especie']]);
        }
        if (! empty($f['raza'])) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('animales_estado as a')->whereColumn('a.establecimiento_id', 'e.id')
                ->where('a.estado', 'activo')->where('a.raza_codigo', $f['raza']));
        }
        if (! empty($f['categoria'])) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('existencias as x')->whereColumn('x.establecimiento_id', 'e.id')
                ->where('x.categoria_codigo', $f['categoria'])->where('x.cabezas', '>', 0));
        }
        if (filter_var($f['con_oferta'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('ofertas as o')->whereColumn('o.establecimiento_id', 'e.id')->whereIn('o.estado', ['publicada', 'reservada']));
        }
        if (filter_var($f['con_contrato'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('contratos_futuro as cf')->whereColumn('cf.establecimiento_id', 'e.id')->whereIn('cf.estado', ['publicado', 'parcialmente_tomado']));
        }

        return $q;
    }

    /** @return array<string, mixed> DirectorioItem */
    public static function item(object $r, string $locale): array
    {
        $traz = PerfilPublicoBuilder::trazabilidad($r->establecimiento_id);
        $insignias = array_values(array_filter([
            $traz['historial_meses'] >= 12 ? 'historial_12_meses' : null,
            (float) $traz['pct_integridad_registrada'] >= 50 ? 'integridad_registrada' : null,
        ]));

        return [
            'slug' => $r->slug,
            'nombre' => $r->nombre,
            'provincia' => Provincias::TODAS[$r->provincia_codigo] ?? '',
            'departamento' => $r->ubicacion_publica === 'provincia' ? null : $r->departamento_nombre,
            'especies' => PgTextArray::parse($r->especies),
            'razas' => DB::table('animales_estado')->where('establecimiento_id', $r->establecimiento_id)->where('estado', 'activo')
                ->whereNotNull('raza_codigo')->distinct()->orderBy('raza_codigo')->pluck('raza_codigo')->all(),
            'ofertas_activas' => (int) $r->ofertas_activas,
            'contratos_abiertos' => DB::table('contratos_futuro')->where('establecimiento_id', $r->establecimiento_id)->whereIn('estado', ['publicado', 'parcialmente_tomado'])->count(),
            'insignias' => $insignias,
            // Portada = primera foto del perfil (bugfix TPA-54).
            'foto_url' => ($portada = Fotos::publica(PgTextArray::parse($r->fotos)[0] ?? null, $locale))['url'] ?? null,
            'foto' => $portada,
            'actualizado_en' => Formato::fechaHora(Carbon::parse($r->ultima_actividad ?? $r->updated_at)),
        ];
    }

    /** @return array<string, mixed> DirectorioFacetas */
    public static function facetas(array $f, string $locale): array
    {
        $ids = self::consulta($f)->pluck('p.establecimiento_id')->all();
        $nombre = fn (string $tabla, string $c) => Catalogos::nombre(DB::table($tabla)->where('codigo', $c)->value('nombres'), $locale) ?? $c;
        $contar = fn ($q, string $col) => $q->selectRaw("{$col} as codigo, count(DISTINCT establecimiento_id) as n")->groupBy($col)->orderByDesc('n')->get();

        return [
            'provincias' => DB::table('establecimientos')->whereIn('id', $ids)->selectRaw('provincia_codigo as codigo, count(*) as n')->groupBy('provincia_codigo')->get()
                ->map(fn ($r) => ['codigo' => $r->codigo, 'nombre' => Provincias::TODAS[$r->codigo] ?? $r->codigo, 'count' => (int) $r->n])->all(),
            'especies' => DB::table('existencias')->whereIn('establecimiento_id', $ids)->where('cabezas', '>', 0)->pipe(fn ($q) => $contar($q, 'especie_codigo'))
                ->map(fn ($r) => ['codigo' => $r->codigo, 'nombre' => $nombre('especies', $r->codigo), 'count' => (int) $r->n])->all(),
            'razas' => DB::table('animales_estado')->whereIn('establecimiento_id', $ids)->where('estado', 'activo')->whereNotNull('raza_codigo')->pipe(fn ($q) => $contar($q, 'raza_codigo'))
                ->map(fn ($r) => ['codigo' => $r->codigo, 'nombre' => $nombre('razas', $r->codigo), 'count' => (int) $r->n])->all(),
            'categorias' => DB::table('existencias')->whereIn('establecimiento_id', $ids)->where('cabezas', '>', 0)->pipe(fn ($q) => $contar($q, 'categoria_codigo'))
                ->map(fn ($r) => ['codigo' => $r->codigo, 'nombre' => $nombre('categorias', $r->codigo), 'count' => (int) $r->n])->all(),
            'con_oferta' => DB::table('ofertas')->whereIn('establecimiento_id', $ids)->whereIn('estado', ['publicada', 'reservada'])->distinct()->count('establecimiento_id'),
            'con_contrato' => DB::table('contratos_futuro')->whereIn('establecimiento_id', $ids)->whereIn('estado', ['publicado', 'parcialmente_tomado'])->distinct()->count('establecimiento_id'),
        ];
    }

    /** @return list<array<string, mixed>> SitemapEntrada (solo lo indexable) */
    public static function sitemap(): array
    {
        $out = [];
        foreach (self::consulta([])->get() as $p) {
            $out[] = ['tipo' => 'perfil', 'path' => "/p/{$p->slug}", 'locales' => PgTextArray::parse($p->idiomas) ?: ['es'],
                'actualizado_en' => Formato::fechaHora(Carbon::parse($p->ultima_actividad ?? $p->updated_at))];
            foreach (DB::table('ofertas')->where('establecimiento_id', $p->establecimiento_id)->whereIn('estado', ['publicada', 'reservada'])->get() as $o) {
                $out[] = ['tipo' => 'oferta', 'path' => "/o/{$o->codigo_publico}", 'locales' => ['es', 'en', 'pt'], 'actualizado_en' => Formato::fechaHora(Carbon::parse($o->updated_at))];
            }
        }

        return $out;
    }
}
