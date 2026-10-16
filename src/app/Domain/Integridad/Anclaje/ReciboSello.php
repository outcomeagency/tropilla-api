<?php

namespace App\Domain\Integridad\Anclaje;

final class ReciboSello
{
    /** @param array<string, mixed> $referencia */
    public function __construct(public readonly string $bytes, public readonly ?string $red, public readonly array $referencia = []) {}
}
