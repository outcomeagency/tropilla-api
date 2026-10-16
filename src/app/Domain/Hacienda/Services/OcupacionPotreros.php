<?php

namespace App\Domain\Hacienda\Services;

use Illuminate\Support\Facades\DB;

/**
 * Ocupación actual de potreros = derivada (modelo-datos §4.1): animales activos ubicados en el potrero
 * (`animales_estado.potrero_id`) + cabezas anónimas de los lotes ubicados ahí (`lotes_estado`), y la
 * lista de esos lotes. Una consulta por cosa para N potreros.
 */
final class OcupacionPotreros
{
    /**
     * @param  list<string>  $potreroIds
     * @return array<string, array{cabezas: int, lotes: list<array{id: string, nombre: string}>}>
     */
    public static function de(string $establecimientoId, array $potreroIds): array
    {
        $out = array_fill_keys($potreroIds, ['cabezas' => 0, 'lotes' => []]);
        if ($potreroIds === []) {
            return $out;
        }

        // Animales individuales: su potrero propio o, si no tienen, el de su lote.
        $individuales = DB::table('animales_estado as a')
            ->leftJoin('lotes_estado as le', 'le.lote_id', '=', 'a.lote_id')
            ->where('a.establecimiento_id', $establecimientoId)->where('a.estado', 'activo')
            ->selectRaw('COALESCE(a.potrero_id, le.potrero_id) as potrero_id, count(*) as n')
            ->groupByRaw('COALESCE(a.potrero_id, le.potrero_id)')->pluck('n', 'potrero_id');
        $lotes = DB::table('lotes_estado as le')->join('lotes as l', 'l.id', '=', 'le.lote_id')
            ->where('le.establecimiento_id', $establecimientoId)->whereIn('le.potrero_id', $potreroIds)->whereNull('l.archivado_en')
            ->orderBy('l.nombre')->get(['le.potrero_id', 'l.id', 'l.nombre', 'le.cabezas_anonimas']);

        foreach ($potreroIds as $p) {
            $out[$p]['cabezas'] = (int) ($individuales[$p] ?? 0);
        }
        foreach ($lotes as $l) {
            $out[$l->potrero_id]['cabezas'] += (int) $l->cabezas_anonimas;
            $out[$l->potrero_id]['lotes'][] = ['id' => $l->id, 'nombre' => $l->nombre];
        }

        return $out;
    }
}
