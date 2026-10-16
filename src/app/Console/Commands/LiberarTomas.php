<?php

namespace App\Console\Commands;

use App\Domain\Contratos\Actions\GestionarTomas;
use Illuminate\Console\Command;

/** Cada 15 min (§9.5): tomas sin «recibí» del productor vencidas (48 h por defecto, configurable por contrato) → liberadas. */
class LiberarTomas extends Command
{
    protected $signature = 'contratos:liberar-tomas';

    protected $description = 'Libera las tomas de tokens sin anticipo confirmado vencidas';

    public function handle(GestionarTomas $tomas): int
    {
        $this->info('Tomas liberadas: '.$tomas->liberarVencidas());

        return self::SUCCESS;
    }
}
