<?php

namespace App\Http\Resources\V1\Auth;

use App\Domain\Establecimientos\Models\Membresia;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Schema `MembresiaResumen`: membresías activas del usuario con las abilities calculadas
 * (la UI muestra/oculta acciones con esta lista; nunca reimplementa la matriz).
 */
final class MembresiaResumenResource
{
    /** @return list<array<string, mixed>> */
    public static function deUsuario(User $usuario, Request $request): array
    {
        return Membresia::query()->activas()
            ->where('usuario_id', $usuario->id)
            ->whereHas('establecimiento', fn ($q) => $q->whereNull('archivado_en'))
            ->with('establecimiento.productor')
            ->orderBy('aceptada_en')
            ->get()
            ->map(fn (Membresia $m) => [
                'id' => $m->id,
                'establecimiento_id' => $m->establecimiento_id,
                'establecimiento_nombre' => $m->establecimiento->nombre,
                'productor_nombre' => $m->establecimiento->productor->nombre,
                'rol' => $m->rol->value,
                'abilities' => $m->rol->abilities(),
            ])->values()->all();
    }
}
