<?php

namespace App\Domain\Clima\Jobs;

use App\Domain\Clima\Acciones\GenerarEscenariosClimaticos;
use App\Domain\Establecimientos\Models\Establecimiento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class CalcularEscenariosClimaticos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $establecimientoId) {}

    public function handle(GenerarEscenariosClimaticos $accion): void
    {
        $establecimiento = Establecimiento::query()->find($this->establecimientoId);
        if ($establecimiento !== null) {
            $accion->handle($establecimiento);
        }
    }
}
