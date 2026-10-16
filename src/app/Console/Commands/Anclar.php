<?php

namespace App\Console\Commands;

use App\Domain\Integridad\Actions\AnclarRegistros;
use Illuminate\Console\Command;

/** Diario 03:00 ART (sync §6.2): ancla los registros nuevos. */
class Anclar extends Command
{
    protected $signature = 'integridad:anclar';

    protected $description = 'Arma el Merkle de los registros sin anclar y sella la raíz con el AnchorDriver';

    public function handle(AnclarRegistros $anclar): int
    {
        $id = $anclar->anclar();
        $this->info($id ? "Anclaje {$id} creado." : 'Nada para anclar.');

        return self::SUCCESS;
    }
}
