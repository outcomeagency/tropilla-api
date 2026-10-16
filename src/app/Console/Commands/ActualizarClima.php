<?php

namespace App\Console\Commands;

use App\Domain\Clima\Actions\ActualizarClima as Accion;
use App\Domain\Establecimientos\Models\Establecimiento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Cada 6 h (TPA-70): recalcula alertas climáticas por establecimiento y avisa las nuevas (in-app + push si hay FCM). */
class ActualizarClima extends Command
{
    protected $signature = 'clima:actualizar {--establecimiento= : Solo este establecimiento} {--sin-avisos}';

    protected $description = 'Consulta pronóstico (Open-Meteo) y ENSO (NOAA CPC) y recalcula las alertas climáticas';

    public function handle(Accion $accion): int
    {
        $n = 0;
        $q = Establecimiento::query()->whereNull('archivado_en')->when($this->option('establecimiento'), fn ($q, $id) => $q->whereKey($id));
        foreach ($q->cursor() as $est) {
            try {
                $n += $accion->handle($est, ! $this->option('sin-avisos'));
            } catch (Throwable $e) {
                Log::error('clima: no se pudo actualizar', ['establecimiento' => $est->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("Alertas climáticas nuevas: {$n}");

        return self::SUCCESS;
    }
}
