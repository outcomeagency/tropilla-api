<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Selector de establecimiento (TPA-12): los campos donde el usuario tiene membresía activa,
 * no archivados, ordenados por productor y nombre. La membresía se precarga en la relación
 * `membresiaActual` para que el Resource no consulte el rol por cada fila.
 */
final class ListarMisEstablecimientos
{
    /** @return Collection<int, Establecimiento> */
    public function handle(User $usuario): Collection
    {
        return Membresia::query()->activas()
            ->where('usuario_id', $usuario->id)
            ->whereHas('establecimiento', fn ($q) => $q->whereNull('archivado_en'))
            ->with('establecimiento.productor')
            ->get()
            ->map(function (Membresia $m) {
                $e = $m->establecimiento;
                $e->setRelation('membresiaActual', $m);

                return $e;
            })
            ->sortBy(fn (Establecimiento $e) => mb_strtolower($e->productor->nombre.'|'.$e->nombre))
            ->values();
    }
}
