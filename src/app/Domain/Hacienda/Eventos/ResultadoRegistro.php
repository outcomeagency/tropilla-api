<?php

namespace App\Domain\Hacienda\Eventos;

use App\Domain\Hacienda\Models\Evento;

/**
 * Resultado de registrar un sobre (sync-e-integridad.md §7.3): `aceptado`, `duplicado` o `en_revision`.
 * Los rechazos no llegan acá: son `EventoRechazado`.
 */
final class ResultadoRegistro
{
    /**
     * @param  list<string>  $observaciones
     * @param  array<string, mixed>|null  $versionVigente
     */
    public function __construct(
        public readonly string $estado,
        public readonly Evento $evento,
        public readonly int $seq,
        public readonly string $eslabon,
        public readonly array $observaciones = [],
        public readonly ?string $revisionId = null,
        public readonly ?array $versionVigente = null,
    ) {}
}
