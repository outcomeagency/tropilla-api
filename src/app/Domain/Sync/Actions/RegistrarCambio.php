<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Integridad\Models\Cadena;
use Illuminate\Support\Facades\DB;

/**
 * Agrega una fila a `cambios` (cursor de pull, sync-e-integridad.md §7.6) con `seq` asignado
 * bajo el lock de la cadena del establecimiento (S7: orden de commit = orden de seq).
 * Debe llamarse dentro de la transacción que hizo el cambio.
 */
final class RegistrarCambio
{
    public function handle(string $establecimientoId, string $entidad, string $entidadId, string $op = 'upsert', string $visibilidad = 'general', ?Cadena $cadena = null): int
    {
        $cadena ??= Cadena::bloquearDeEstablecimiento($establecimientoId);
        $seq = $cadena->ultimo_seq_cambios + 1;

        DB::table('cambios')->insert([
            'establecimiento_id' => $establecimientoId,
            'seq' => $seq,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'op' => $op,
            'visibilidad' => $visibilidad,
            'created_at' => now(),
        ]);

        $cadena->ultimo_seq_cambios = $seq;
        Cadena::query()->whereKey($cadena->id)->update(['ultimo_seq_cambios' => $seq]);

        return $seq;
    }
}
