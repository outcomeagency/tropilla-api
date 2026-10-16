<?php

namespace App\Domain\Establecimientos;

use Illuminate\Support\Facades\DB;

/**
 * La interdicción no es un campo: es el evento `establecimiento.interdiccion` vigente sin un
 * `establecimiento.levantamiento_interdiccion` que lo referencie (modelo-datos §2.4).
 */
final class Interdiccion
{
    /** @return array{evento_id: string, motivo: string, inicio: string, levantamiento_estimado: ?string}|null */
    public static function vigente(string $establecimientoId): ?array
    {
        $vigentes = DB::table('eventos_vigentes')->where('establecimiento_id', $establecimientoId)->where('accion', '<>', 'anulacion')
            ->whereIn('tipo', ['establecimiento.interdiccion', 'establecimiento.levantamiento_interdiccion'])
            ->orderBy('ocurrido_en')->get(['evento_raiz_id', 'tipo', 'payload']);

        $abiertas = [];
        foreach ($vigentes as $e) {
            $p = json_decode($e->payload, true);
            if ($e->tipo === 'establecimiento.interdiccion') {
                $abiertas[$e->evento_raiz_id] = $p;
            } else {
                unset($abiertas[$p['interdiccion_evento_id'] ?? '']);
            }
        }
        if ($abiertas === []) {
            return null;
        }
        $id = array_key_last($abiertas);
        $p = $abiertas[$id];

        return ['evento_id' => $id, 'motivo' => $p['motivo'], 'inicio' => $p['inicio'], 'levantamiento_estimado' => $p['levantamiento_estimado'] ?? null];
    }
}
