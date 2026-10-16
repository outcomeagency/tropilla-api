<?php

namespace App\Console\Commands;

use App\Domain\Busco\Coincidencias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Diario: Busco con plazo vencido → `vencida`; las abiertas recalculan coincidencias (las existencias cambian). */
class ActualizarBuscos extends Command
{
    protected $signature = 'busco:actualizar';

    protected $description = 'Vence los Busco con plazo cumplido y recalcula las coincidencias de los abiertos';

    public function handle(Coincidencias $coincidencias): int
    {
        $vencidos = DB::table('buscos')->where('estado', 'abierta')->whereNotNull('plazo_hasta')->where('plazo_hasta', '<', now()->toDateString())
            ->update(['estado' => 'vencida', 'updated_at' => now()]);
        $n = 0;
        foreach (DB::table('buscos')->whereIn('estado', ['abierta', 'vencida'])->cursor() as $b) {
            $n += $coincidencias->calcular($b);
        }
        $this->info("Vencidos: {$vencidos} · coincidencias vigentes: {$n}");

        return self::SUCCESS;
    }
}
