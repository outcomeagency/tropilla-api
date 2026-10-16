<?php

namespace App\Domain\Establecimientos\Policies;

use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Membresia;
use App\Domain\Establecimientos\Models\Productor;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Collection;

/**
 * El productor se ve/edita a través de las membresías en **cualquiera** de sus establecimientos
 * (modelo-datos.md §2.3): ver = alguna membresía activa; editar / ver CUIT = dueño o admin de alguno.
 */
class ProductorPolicy
{
    public function view(User $usuario, Productor $productor): Response
    {
        return self::roles($usuario, $productor)->isEmpty() ? Response::denyAsNotFound() : Response::allow();
    }

    public function update(User $usuario, Productor $productor): Response
    {
        $roles = self::roles($usuario, $productor);
        if ($roles->isEmpty()) {
            return Response::denyAsNotFound();
        }

        return $roles->contains(fn (Rol $r) => $r->puede('establecimiento.editar'))
            ? Response::allow()
            : Response::deny(__('Tu rol no permite editar el productor.'), 'sin_permiso');
    }

    public static function puedeVerCuit(User $usuario, Productor $productor): bool
    {
        return self::roles($usuario, $productor)->contains(fn (Rol $r) => $r->puede('establecimiento.ver_privados'));
    }

    /** @return Collection<int, Rol> */
    public static function roles(User $usuario, Productor $productor): Collection
    {
        return Membresia::query()->activas()
            ->where('usuario_id', $usuario->id)
            ->whereHas('establecimiento', fn ($q) => $q->where('productor_id', $productor->id))
            ->pluck('rol');
    }

    /** Un establecimiento del productor donde el usuario puede editar (para la auditoría). */
    public static function establecimientoEditable(User $usuario, Productor $productor): ?string
    {
        return Membresia::query()->activas()
            ->where('usuario_id', $usuario->id)
            ->whereIn('rol', ['dueno', 'admin'])
            ->whereHas('establecimiento', fn ($q) => $q->where('productor_id', $productor->id))
            ->value('establecimiento_id');
    }
}
