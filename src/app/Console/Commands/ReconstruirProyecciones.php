<?php

namespace App\Console\Commands;

use App\Domain\Hacienda\Proyecciones\Proyector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** modelo-datos.md §6.5: re-aplica todos los eventos vigentes (al desplegar un cambio de proyector). */
class ReconstruirProyecciones extends Command
{
    protected $signature = 'proyecciones:reconstruir {establecimiento? : id; sin argumento, todos}';

    protected $description = 'Reconstruye animales_estado, lotes, conteos y existencias desde los eventos vigentes';

    public function handle(Proyector $proyector): int
    {
        $ids = $this->argument('establecimiento') ? [$this->argument('establecimiento')] : DB::table('establecimientos')->pluck('id')->all();
        foreach ($ids as $id) {
            $proyector->reconstruir($id);
            $this->info("Reconstruido {$id}");
        }

        return self::SUCCESS;
    }
}
