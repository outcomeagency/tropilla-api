<?php

namespace App\Domain\Documentos\Borradores\Extraccion;

final class ResultadoExtraccion
{
    /** @param array<string, mixed> $salida */
    public function __construct(
        public readonly array $salida,
        public readonly string $proveedor,
        public readonly string $modelo,
        public readonly ?int $tokensEntrada = null,
        public readonly ?int $tokensSalida = null,
    ) {}
}
