<?php

namespace App\Console\Commands;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sanidad\GeneradorTareas;
use Illuminate\Console\Command;

/** Job diario (modelo-datos §7): tareas del plan sanitario con horizonte de 90 días. */
class GenerarTareas extends Command
{
    protected $signature = 'sanidad:generar-tareas {establecimiento?}';

    protected $description = 'Genera/sincroniza las tareas del plan sanitario (horizonte 90 días)';

    public function handle(GeneradorTareas $generador): int
    {
        $total = 0;
        $q = Establecimiento::query()->whereIn('id', fn ($s) => $s->select('establecimiento_id')->from('planes_sanitarios'));
        if ($this->argument('establecimiento')) {
            $q->whereKey($this->argument('establecimiento'));
        }
        foreach ($q->cursor() as $est) {
            $total += $generador->generar($est);
        }
        $this->info("Tareas creadas/actualizadas: {$total}");

        return self::SUCCESS;
    }
}
