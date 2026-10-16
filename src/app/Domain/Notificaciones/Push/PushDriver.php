<?php

namespace App\Domain\Notificaciones\Push;

/** Envío push (TPA-53). FCM en staging/producción; `log` en local/testing. */
interface PushDriver
{
    /**
     * @param  array<string, string>  $datos
     * @return bool false = el token ya no es válido (se borra del dispositivo)
     */
    public function enviar(string $token, string $titulo, string $cuerpo, array $datos): bool;
}
