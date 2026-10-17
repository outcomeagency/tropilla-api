<?php

namespace App\Domain\Ia;

use RuntimeException;

/** Falla del proveedor de IA. `reintentable`: error transitorio (red, 429, 5xx). El mensaje nunca incluye la key. */
class GeminiError extends RuntimeException
{
    public function __construct(string $mensaje, public readonly bool $reintentable = false)
    {
        parent::__construct($mensaje);
    }
}
