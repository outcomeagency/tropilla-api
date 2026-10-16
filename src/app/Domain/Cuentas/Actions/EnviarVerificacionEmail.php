<?php

namespace App\Domain\Cuentas\Actions;

use App\Domain\Cuentas\Notifications\VerificarEmailNotification;
use App\Domain\Cuentas\TokenVerificacionEmail;
use App\Exceptions\ApiException;
use App\Models\User;

final class EnviarVerificacionEmail
{
    public function handle(User $usuario): void
    {
        if ($usuario->email === null) {
            throw ApiException::validacion(__('La cuenta no tiene email.'), ['email' => [__('La cuenta no tiene email.')]]);
        }

        if ($usuario->emailVerificado()) {
            return;
        }

        $usuario->notify(new VerificarEmailNotification(TokenVerificacionEmail::generar($usuario)));
    }
}
