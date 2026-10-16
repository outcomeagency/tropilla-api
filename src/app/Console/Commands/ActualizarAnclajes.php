<?php

namespace App\Console\Commands;

use App\Domain\Integridad\Actions\AnclarRegistros;
use Illuminate\Console\Command;

/** Cada 6 h (sync §6.2): reintenta sellos pendientes y confirma los enviados. */
class ActualizarAnclajes extends Command
{
    protected $signature = 'integridad:actualizar-anclajes';

    protected $description = 'Reintenta sellos pendientes y consulta la confirmación de los anclajes enviados';

    public function handle(AnclarRegistros $anclar): int
    {
        $r = $anclar->actualizar();
        $this->info("Sellados: {$r['sellados']} · confirmados: {$r['confirmados']} · fallidos: {$r['fallidos']}");

        return self::SUCCESS;
    }
}
