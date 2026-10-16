<?php

namespace App\Domain\Hacienda;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Lecturas de animales: `animales_estado` + nombres de lote, potrero y categoría (AnimalResumen / Animal). */
final class ConsultasAnimales
{
    public static function base(string $establecimientoId): Builder
    {
        return DB::table('animales_estado as a')
            ->leftJoin('lotes as l', 'l.id', '=', 'a.lote_id')
            ->leftJoin('lotes_estado as le', 'le.lote_id', '=', 'a.lote_id')
            ->leftJoin('potreros as p', 'p.id', '=', DB::raw('COALESCE(a.potrero_id, le.potrero_id)'))
            ->leftJoin('categorias as c', 'c.codigo', '=', 'a.categoria_codigo')
            ->where('a.establecimiento_id', $establecimientoId)
            ->select('a.*', 'l.nombre as lote_nombre', 'p.id as potrero_actual_id', 'p.nombre as potrero_nombre', 'c.nombres as categoria_nombres', 'c.orden as categoria_orden');
    }

    public static function uno(string $establecimientoId, string $animalId): ?object
    {
        return self::base($establecimientoId)->where('a.animal_id', $animalId)->first();
    }

    /** @param array<string, mixed> $f */
    public static function listado(string $establecimientoId, array $f): Builder
    {
        $hoy = now()->toDateString();
        $q = self::base($establecimientoId);

        $estado = $f['estado'] ?? 'activo';
        if ($estado !== 'todos') {
            $q->where('a.estado', $estado);
        }
        if (! empty($f['q'])) {
            $texto = Proyecciones\Identificaciones::normalizar((string) $f['q']);
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('animal_identificaciones as i')
                ->whereColumn('i.animal_id', 'a.animal_id')
                ->where('i.valor_normalizado', 'like', '%'.addcslashes($texto, '%_\\').'%'));
        }
        foreach (['especie' => 'a.especie_codigo', 'categoria' => 'a.categoria_codigo', 'lote_id' => 'a.lote_id',
            'estado_reproductivo' => 'a.estado_reproductivo', 'sexo' => 'a.sexo'] as $param => $col) {
            if (! empty($f[$param])) {
                $q->where($col, $f[$param]);
            }
        }
        if (! empty($f['potrero_id'])) {
            $q->where(DB::raw('COALESCE(a.potrero_id, le.potrero_id)'), $f['potrero_id']);
        }
        match ($f['sanidad'] ?? null) {
            'retiro' => $q->where(fn ($w) => $w->where('a.retiro_carne_hasta', '>=', $hoy)->orWhere('a.retiro_leche_hasta', '>=', $hoy)),
            'reactor' => $q->where('a.reactor', true),
            default => null,
        };
        if (filter_var($f['sugerencia_categoria'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $q->whereNotNull('a.categoria_sugerida_codigo');
        }

        match ($f['orden'] ?? 'identificacion') {
            'categoria' => $q->orderBy('c.orden')->orderBy('a.especie_codigo'),
            'peso' => $q->orderByRaw('a.peso_kg DESC NULLS LAST'),
            'edad' => $q->orderByRaw('a.fecha_nacimiento ASC NULLS LAST'),
            'reciente' => $q->orderByDesc('a.ultimo_evento_ocurrido_en'),
            default => $q->orderByRaw('a.identificacion_principal ASC NULLS LAST'),
        };

        return $q->orderBy('a.animal_id');
    }
}
