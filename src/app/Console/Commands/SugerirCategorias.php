<?php

namespace App\Console\Commands;

use App\Domain\Hacienda\Proyecciones\SugeridorCategoria;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sync\Actions\RegistrarCambio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Job diario (modelo-datos §6.4): la edad cambia sola, así que se reevalúan las reglas de categoría de los animales
 * activos y, si la sugerencia cambió, se actualiza la proyección y se agrega un cambio para el pull.
 */
class SugerirCategorias extends Command
{
    protected $signature = 'hacienda:sugerir-categorias {establecimiento?}';

    protected $description = 'Reevalúa las sugerencias de recategorización (por edad) de los animales activos';

    public function handle(SugeridorCategoria $sugeridor, RegistrarCambio $cambio): int
    {
        $cambiados = 0;
        $ests = $this->argument('establecimiento') ? [$this->argument('establecimiento')] : DB::table('establecimientos')->pluck('id')->all();
        foreach ($ests as $est) {
            DB::transaction(function () use ($est, $sugeridor, $cambio, &$cambiados) {
                $cadena = Cadena::bloquearDeEstablecimiento($est);
                foreach (DB::table('animales_estado')->where('establecimiento_id', $est)->where('estado', 'activo')->cursor() as $a) {
                    [$sug, $regla] = $sugeridor->evaluar((array) $a, $est);
                    if ($sug !== $a->categoria_sugerida_codigo) {
                        DB::table('animales_estado')->where('animal_id', $a->animal_id)->update([
                            'categoria_sugerida_codigo' => $sug, 'categoria_sugerida_regla_id' => $regla, 'categoria_sugerida_en' => $sug ? now() : null,
                        ]);
                        $cambio->handle($est, 'animal', $a->animal_id, cadena: $cadena);
                        $cambiados++;
                    }
                }
            });
        }
        $this->info("Sugerencias actualizadas: {$cambiados}");

        return self::SUCCESS;
    }
}
