<?php

namespace App\Domain\Cuentas\Actions;

use App\Models\User;

/** PATCH /auth/me: nombre, idioma, país, foto (sha256). Email y teléfono no se cambian acá. */
final class ActualizarUsuario
{
    /** @param array<string, mixed> $datos */
    public function handle(User $usuario, array $datos): User
    {
        $usuario->fill(array_intersect_key($datos, array_flip(['nombre', 'idioma', 'pais', 'foto_sha256'])))->save();

        return $usuario;
    }
}
