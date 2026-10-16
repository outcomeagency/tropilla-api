<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Domain\Establecimientos\Models\Productor;
use App\Exceptions\ApiException;
use App\Models\User;

/**
 * POST /establecimientos: nuevo establecimiento de un productor existente.
 * Requiere ser dueño o admin (membresía activa) de otro establecimiento del productor;
 * si no tiene ninguna membresía en el productor → 404 (no filtra existencia).
 */
final class CrearEstablecimientoDeProductor
{
    public function __construct(private readonly CrearEstablecimiento $crear) {}

    /** @param array<string, mixed> $datos */
    public function handle(User $usuario, array $datos): Establecimiento
    {
        $roles = Membresia::query()->activas()
            ->where('usuario_id', $usuario->id)
            ->whereHas('establecimiento', fn ($q) => $q->where('productor_id', $datos['productor_id']))
            ->pluck('rol');

        if ($roles->isEmpty()) {
            throw ApiException::validacion(__('El productor no existe.'), ['productor_id' => [__('El productor no existe.')]]);
        }
        if (! $roles->contains(fn (Rol $r) => in_array($r, [Rol::Dueno, Rol::Admin], true))) {
            throw ApiException::sinPermiso(__('Solo el dueño o un administrador del productor puede agregar establecimientos.'));
        }

        return $this->crear->handle(Productor::query()->findOrFail($datos['productor_id']), $usuario, $datos);
    }
}
