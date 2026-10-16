<?php

namespace App\Domain\Documentos;

/** Resultado de registrar una versión de documento (mismo vocabulario que el push de eventos). */
final class ResultadoDocumento
{
    public function __construct(
        public readonly string $estado,
        public readonly string $versionId,
        public readonly string $documentoId,
        public readonly int $seq,
        public readonly string $eslabon,
        public readonly string $registradoEn,
        public readonly ?string $revisionId = null,
    ) {}
}
