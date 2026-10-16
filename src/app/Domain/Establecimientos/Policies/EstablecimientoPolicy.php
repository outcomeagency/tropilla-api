<?php

namespace App\Domain\Establecimientos\Policies;

use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Autorización por **membresía activa + ability del rol** (modelo-datos.md §13).
 * Cada ability de `Rol::MATRIZ` es un Gate que delega acá (ver AppServiceProvider).
 *
 * - Sin membresía (o establecimiento archivado): 404 `no_encontrado` (api-v1 A6: no filtrar existencia).
 * - Membresía revocada (y ninguna activa): 403 `membresia_revocada` (la app borra su copia local).
 * - Rol sin la ability: 403 `sin_permiso`.
 */
class EstablecimientoPolicy
{
    public function permite(User $usuario, Establecimiento $establecimiento, string $ability): Response
    {
        $membresia = self::membresia($usuario, $establecimiento);

        if ($membresia === null) {
            $revocada = Membresia::query()
                ->where('establecimiento_id', $establecimiento->id)
                ->where('usuario_id', $usuario->id)
                ->where('estado', 'revocada')
                ->exists();

            return $revocada
                ? Response::deny(__('Tu acceso a este establecimiento fue revocado.'), 'membresia_revocada')
                : Response::denyAsNotFound(__('No encontrado.'));
        }

        if ($establecimiento->archivado_en !== null && $ability !== 'establecimiento.ver') {
            return Response::deny(__('El establecimiento está archivado.'), 'sin_permiso');
        }

        return $membresia->rol->puede($ability)
            ? Response::allow()
            : Response::deny(__('Tu rol no permite esta acción.'), 'sin_permiso');
    }

    public static function membresia(User $usuario, Establecimiento $establecimiento): ?Membresia
    {
        /** @var Membresia|null */
        return Membresia::query()
            ->where('establecimiento_id', $establecimiento->id)
            ->where('usuario_id', $usuario->id)
            ->where('estado', 'activa')
            ->first();
    }

    public static function rol(User $usuario, Establecimiento $establecimiento): ?Rol
    {
        return self::membresia($usuario, $establecimiento)?->rol;
    }
}
