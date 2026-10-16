<?php

namespace App\Domain\Hacienda\Eventos;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Consultas de lectura sobre `eventos_vigentes` para actividad y timelines (EventoResumen). */
final class ConsultasEventos
{
    public static function base(): Builder
    {
        return DB::table('eventos_vigentes as v')
            ->leftJoin('usuarios as u', 'u.id', '=', 'v.autor_id')
            ->select('v.*', 'u.nombre as autor_nombre')
            ->selectRaw("(SELECT m.rol FROM membresias m WHERE m.establecimiento_id = v.establecimiento_id AND m.usuario_id = v.autor_id ORDER BY (m.estado = 'activa') DESC, m.aceptada_en DESC LIMIT 1) AS autor_rol")
            ->selectRaw('(SELECT count(*) FROM evento_animales ea WHERE ea.evento_id = v.id) AS animales_count');
    }

    /** @param array<string, mixed> $f filtros: tipo, modulo, desde, hasta, autor_id, lote_id */
    public static function actividad(string $establecimientoId, array $f): Builder
    {
        return self::base()
            ->where('v.establecimiento_id', $establecimientoId)
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('v.tipo', $v))
            ->when($f['modulo'] ?? null, fn ($q, $v) => $q->where('v.tipo', 'like', $v.'.%'))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('v.ocurrido_en', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('v.ocurrido_en', '<', Carbon::parse($v)->addDay()->toDateString()))
            ->when($f['autor_id'] ?? null, fn ($q, $v) => $q->where('v.autor_id', $v))
            ->when($f['lote_id'] ?? null, fn ($q, $v) => $q->where('v.lote_id', $v))
            ->orderByDesc('v.ocurrido_en')->orderByDesc('v.id');
    }
}
