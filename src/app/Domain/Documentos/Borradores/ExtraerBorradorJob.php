<?php

namespace App\Domain\Documentos\Borradores;

use App\Domain\Documentos\Borradores\Actions\ExtraerBorrador;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Lectura asíncrona de un borrador (202 + polling). Los reintentos de errores transitorios los maneja la Action. */
class ExtraerBorradorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $borradorId) {}

    public function handle(ExtraerBorrador $extraer): void
    {
        $extraer->handle($this->borradorId);
    }
}
