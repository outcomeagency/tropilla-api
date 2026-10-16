<?php

namespace App\Domain\Cuentas\Actions;

use App\Domain\Cuentas\TokenVerificacionEmail;
use App\Exceptions\ApiException;

final class VerificarEmail
{
    public function handle(string $token): void
    {
        $usuario = TokenVerificacionEmail::validar($token);

        if ($usuario === null) {
            throw ApiException::validacion(__('El enlace de verificación no es válido o venció.'), ['token' => [__('Token inválido o vencido.')]]);
        }

        if (! $usuario->emailVerificado()) {
            $usuario->forceFill(['email_verificado_en' => now()])->save();
        }
    }
}
