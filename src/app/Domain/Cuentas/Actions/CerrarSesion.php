<?php

namespace App\Domain\Cuentas\Actions;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Logout: revoca solo el token actual (api-v1.md §2). El dispositivo queda
 * registrado (token_id → null por FK) para que un re-login lo reutilice.
 */
final class CerrarSesion
{
    public function handle(User $usuario): void
    {
        $token = $usuario->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
