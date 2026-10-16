<?php

namespace App\Domain\Sanidad;

use App\Domain\Establecimientos\Models\Establecimiento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Regenera las tareas de un establecimiento (tras un evento disparador o un cambio del plan). Idempotente. */
class GenerarTareasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $establecimientoId) {}

    public function handle(GeneradorTareas $generador): void
    {
        if ($est = Establecimiento::query()->find($this->establecimientoId)) {
            $generador->generar($est);
        }
    }
}
