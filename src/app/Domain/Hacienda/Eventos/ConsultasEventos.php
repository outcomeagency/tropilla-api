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

    /**
     * Timeline del animal (TPA-17): eventos vigentes que lo tienen como sujeto + eventos grupales del lote
     * anónimo del que se individualizó, anteriores a su individualización (`heredado_de_lote`).
     *
     * @param  array<string, mixed>  $f  tipo, modulo, incluir_anulados
     */
    public static function timelineAnimal(object $animal, array $f): Builder
    {
        $loteOrigen = $animal->lote_origen_anonimo_id;
        $desde = $loteOrigen ? DB::table('eventos')->where('id', $animal->evento_origen_raiz_id)->value('ocurrido_en') : null;
        $propio = fn ($q) => $q->select(DB::raw(1))->from('evento_animales as ea')->whereColumn('ea.evento_id', 'v.id')->where('ea.animal_id', $animal->id);

        return self::base()
            ->where('v.establecimiento_id', $animal->establecimiento_id)
            ->where(function ($q) use ($propio, $loteOrigen, $desde) {
                $q->whereExists($propio);
                if ($loteOrigen) {
                    $q->orWhere(fn ($q2) => $q2->where('v.lote_id', $loteOrigen)->where('v.sujeto_tipo', 'lote')->where('v.ocurrido_en', '<', $desde));
                }
            })
            ->selectRaw('NOT EXISTS (SELECT 1 FROM evento_animales ea3 WHERE ea3.evento_id = v.id AND ea3.animal_id = ?) AS heredado_de_lote', [$animal->id])
            ->when(empty($f['incluir_anulados']) || $f['incluir_anulados'] === 'false', fn ($q) => $q->where('v.accion', '<>', 'anulacion'))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('v.tipo', $v))
            ->when($f['modulo'] ?? null, fn ($q, $v) => $q->where('v.tipo', 'like', $v.'.%'))
            ->orderByDesc('v.ocurrido_en')->orderByDesc('v.id');
    }

    /** Timeline del lote (TPA-20): eventos vigentes con ese lote como sujeto o contexto. */
    public static function timelineLote(string $establecimientoId, string $loteId, ?string $tipo): Builder
    {
        return self::base()->where('v.establecimiento_id', $establecimientoId)->where('v.lote_id', $loteId)
            ->where('v.accion', '<>', 'anulacion')
            ->when($tipo, fn ($q, $v) => $q->where('v.tipo', $v))
            ->orderByDesc('v.ocurrido_en')->orderByDesc('v.id');
    }
}
