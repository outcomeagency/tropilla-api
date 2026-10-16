<?php

namespace Database\Seeders;

use App\Domain\Catalogos\Catalogos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogos globales (reglas como datos). Idempotente: corre en cada arranque y en los tests.
 * Si el contenido global cambió, agrega una fila en `catalogo_versiones` (la app baja catálogos
 * cuando `catalogos_version` sube, sync-e-integridad.md §7.6).
 */
class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([GeoDepartamentosSeeder::class, EspeciesSeeder::class, TiposYListasSeeder::class]);

        $huella = 'seed:'.Catalogos::huellaContenido();
        $ultima = DB::table('catalogo_versiones')->orderByDesc('version')->value('motivo');
        if ($ultima !== $huella) {
            DB::table('catalogo_versiones')->insert(['motivo' => $huella, 'created_at' => now()]);
        }
        Catalogos::olvidar();
    }
}
