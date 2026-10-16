<?php

namespace App\Domain\Sistema\Data;

/**
 * Resultado de VerificarSalud: un chequeo por dependencia.
 *
 * @phpstan-type Chequeo array{ok: bool, latencia_ms: float, detalle?: string|null}
 */
final readonly class EstadoDelSistema
{
    /**
     * @param  array<string, Chequeo>  $chequeos
     */
    public function __construct(
        public array $chequeos,
    ) {}

    public function ok(): bool
    {
        foreach ($this->chequeos as $chequeo) {
            if (! $chequeo['ok']) {
                return false;
            }
        }

        return true;
    }
}
