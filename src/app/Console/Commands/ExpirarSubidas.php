<?php

namespace App\Console\Commands;

use App\Domain\Documentos\Actions\GestionarSubida;
use Illuminate\Console\Command;

/** Cada hora: subidas vencidas → expirada (aborta el multipart en el almacén). */
class ExpirarSubidas extends Command
{
    protected $signature = 'archivos:expirar-subidas';

    protected $description = 'Marca como expiradas las subidas diferidas vencidas';

    public function handle(GestionarSubida $subidas): int
    {
        $this->info('Subidas expiradas: '.$subidas->expirarVencidas());

        return self::SUCCESS;
    }
}
