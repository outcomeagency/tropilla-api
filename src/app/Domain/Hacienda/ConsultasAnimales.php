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
            ->select('a.*', 'l.nombre as lote_nombre', 'p.id as potrero_actual_id', 'p.nombre as potrero_nombre', 'c.nombres as categoria_nombres');
    }

    public static function uno(string $establecimientoId, string $animalId): ?object
    {
        return self::base($establecimientoId)->where('a.animal_id', $animalId)->first();
    }
}
