<?php

namespace App\Domain\Notificaciones\Push;

use Illuminate\Support\Facades\Log;

/** Local/testing: no envía nada, deja el push en el log (y lo recuerda para los tests). */
final class LogPushDriver implements PushDriver
{
    /** @var list<array{token: string, titulo: string, cuerpo: string, datos: array<string, string>}> */
    public array $enviados = [];

    public function enviar(string $token, string $titulo, string $cuerpo, array $datos): bool
    {
        $this->enviados[] = compact('token', 'titulo', 'cuerpo', 'datos');
        Log::info('push (log)', ['token' => substr($token, 0, 12).'…', 'titulo' => $titulo]);

        return true;
    }
}
