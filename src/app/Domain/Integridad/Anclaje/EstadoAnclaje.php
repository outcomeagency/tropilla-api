<?php

namespace App\Domain\Integridad\Anclaje;

final class EstadoAnclaje
{
    /** @param array<string, mixed>|null $referencia */
    public function __construct(
        public readonly string $estado,               // enviado | confirmado
        public readonly ?string $reciboNuevo = null,  // recibo actualizado (p. ej. .ots con la atestación de Bitcoin)
        public readonly ?array $referencia = null,
    ) {}
}
