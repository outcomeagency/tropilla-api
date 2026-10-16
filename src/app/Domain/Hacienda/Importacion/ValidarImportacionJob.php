<?php

namespace App\Domain\Hacienda\Importacion;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Validación / confirmación asíncrona de la planilla (202 + polling, api-v1 A10). */
class ValidarImportacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $importacionId, public readonly ?string $confirmarUsuarioId = null) {}

    public function handle(ProcesarImportacion $procesar): void
    {
        $this->confirmarUsuarioId === null
            ? $procesar->validar($this->importacionId)
            : $procesar->confirmar($this->importacionId, User::query()->findOrFail($this->confirmarUsuarioId));
    }
}
