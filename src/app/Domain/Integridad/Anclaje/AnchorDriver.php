<?php

namespace App\Domain\Integridad\Anclaje;

/** Driver de anclaje (sync-e-integridad §6.3): sella la raíz del Merkle y la actualiza hasta confirmar. */
interface AnchorDriver
{
    public function nombre(): string;

    /** Sella la raíz (hex): devuelve el recibo (bytes del `.ots` u otro) y la red. */
    public function sellar(string $raizHex): ReciboSello;

    /** Consulta el estado: si mejora, trae un recibo nuevo (se guarda como objeto nuevo, nada se pisa). */
    public function actualizar(string $raizHex, string $recibo): EstadoAnclaje;
}
