<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** TPA-72: vencida la retención, borra el informe de las consultas de contraparte y deja el registro (quién, cuándo, para qué). */
class DepurarConsultasContraparte extends Command
{
    protected $signature = 'contrapartes:depurar';

    protected $description = 'Borra el detalle de las consultas de contraparte más viejas que la retención configurada';

    public function handle(): int
    {
        $n = DB::table('contraparte_consultas')->whereNull('depurada_en')
            ->where('created_at', '<', now()->subDays((int) config('tropilla.contrapartes.retencion_dias')))
            ->update(['informe' => null, 'denominacion' => null, 'depurada_en' => now()]);
        $this->info("Consultas depuradas: {$n}");

        return self::SUCCESS;
    }
}
