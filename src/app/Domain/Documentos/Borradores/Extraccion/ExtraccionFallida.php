<?php

namespace App\Domain\Documentos\Borradores\Extraccion;

use RuntimeException;

/** La extracción no se pudo hacer. `reintentable`: error transitorio del proveedor (red, 429, 5xx) → el job reintenta. */
class ExtraccionFallida extends RuntimeException
{
    public function __construct(string $mensaje, public readonly bool $reintentable = false)
    {
        parent::__construct($mensaje);
    }
}
