<?php

namespace App\Domain\Integridad\Anclaje;

/** Local y testing: confirma al instante con `referencia = {stub: true}`. Prohibido en producción (AppServiceProvider). */
final class StubDriver implements AnchorDriver
{
    public function nombre(): string
    {
        return 'stub';
    }

    public function sellar(string $raizHex): ReciboSello
    {
        return new ReciboSello("STUB\n{$raizHex}\n", 'stub', ['stub' => true]);
    }

    public function actualizar(string $raizHex, string $recibo): EstadoAnclaje
    {
        return new EstadoAnclaje('confirmado', null, ['stub' => true]);
    }
}
