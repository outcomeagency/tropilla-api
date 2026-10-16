<?php

namespace App\Domain\Sanidad;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve el `alcance` de un ítem/tarea (categorías y banderas del catálogo, lotes, potreros, animales) contra la hacienda
 * activa: animales individuales + cabezas de lotes anónimos. Las banderas leen `categorias` (datos), nunca `if especie`.
 *
 * Claves: `categorias[]`, `excluye_vientres`, `excluye_reproductores`, `excluye_bueyes`, `reposicion` (hembras ni cría ni
 * vientre), `adultos` (vientres y reproductores), `no_destetados`, `animal_ids[]`, `lote_ids[]`, `potrero_ids[]`.
 */
final class AlcanceSanitario
{
    /**
     * Códigos de categoría admitidos (null = todas).
     *
     * @param  array<string, mixed>  $alcance
     * @return list<string>|null
     */
    public static function categorias(?string $especie, array $alcance): ?array
    {
        $flags = array_intersect_key($alcance, array_flip(['excluye_vientres', 'excluye_reproductores', 'excluye_bueyes', 'reposicion', 'adultos']));
        if (empty($alcance['categorias']) && ! array_filter($flags)) {
            return null;
        }
        $q = DB::table('categorias')->when($especie, fn ($q) => $q->where('especie_codigo', $especie));
        if (! empty($alcance['categorias'])) {
            $q->whereIn('codigo', (array) $alcance['categorias']);
        }
        if (! empty($alcance['excluye_vientres'])) {
            $q->where('es_vientre', false);
        }
        if (! empty($alcance['excluye_reproductores'])) {
            $q->where('es_reproductor', false);
        }
        if (! empty($alcance['excluye_bueyes'])) {
            $q->where('codigo', 'not like', '%\_BUEY');
        }
        if (! empty($alcance['reposicion'])) {
            $q->where('sexo', 'H')->where('es_cria', false)->where('es_vientre', false);
        }
        if (! empty($alcance['adultos'])) {
            $q->where(fn ($w) => $w->where('es_vientre', true)->orWhere('es_reproductor', true));
        }

        return $q->pluck('codigo')->all();
    }

    /** @param array<string, mixed> $alcance */
    public static function individuales(string $establecimientoId, ?string $especie, array $alcance): Builder
    {
        $cats = self::categorias($especie, $alcance);

        return DB::table('animales_estado as e')->leftJoin('lotes_estado as le', 'le.lote_id', '=', 'e.lote_id')
            ->where('e.establecimiento_id', $establecimientoId)->where('e.estado', 'activo')
            ->when($especie, fn ($q) => $q->where('e.especie_codigo', $especie))
            ->when($cats !== null, fn ($q) => $q->whereIn('e.categoria_codigo', $cats))
            ->when(! empty($alcance['no_destetados']), fn ($q) => $q->where('e.destetado', false))
            ->when(! empty($alcance['animal_ids']), fn ($q) => $q->whereIn('e.animal_id', (array) $alcance['animal_ids']))
            ->when(! empty($alcance['lote_ids']), fn ($q) => $q->whereIn('e.lote_id', (array) $alcance['lote_ids']))
            ->when(! empty($alcance['potrero_ids']), fn ($q) => $q->whereIn(DB::raw('COALESCE(e.potrero_id, le.potrero_id)'), (array) $alcance['potrero_ids']));
    }

    /** Cabezas estimadas: individuales + lotes anónimos (los anónimos no se filtran por destete ni animal). @param array<string, mixed> $alcance */
    public static function cabezas(string $establecimientoId, ?string $especie, array $alcance): int
    {
        $n = self::individuales($establecimientoId, $especie, $alcance)->count();
        if (! empty($alcance['animal_ids'])) {
            return $n;
        }
        $cats = self::categorias($especie, $alcance);
        $anon = DB::table('lote_conteos as c')->join('lotes as l', 'l.id', '=', 'c.lote_id')->leftJoin('lotes_estado as le', 'le.lote_id', '=', 'c.lote_id')
            ->where('c.establecimiento_id', $establecimientoId)->whereNull('l.archivado_en')->where('c.cabezas', '>', 0)
            ->when($especie, fn ($q) => $q->where('l.especie_codigo', $especie))
            ->when($cats !== null, fn ($q) => $q->whereIn('c.categoria_codigo', $cats))
            ->when(! empty($alcance['lote_ids']), fn ($q) => $q->whereIn('c.lote_id', (array) $alcance['lote_ids']))
            ->when(! empty($alcance['potrero_ids']), fn ($q) => $q->whereIn('le.potrero_id', (array) $alcance['potrero_ids']))
            ->sum('c.cabezas');

        return $n + (int) $anon;
    }
}
