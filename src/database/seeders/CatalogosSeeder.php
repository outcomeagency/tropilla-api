<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Catálogos globales (reglas como datos). Idempotente: corre en cada arranque y en los tests.
 */
class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(GeoDepartamentosSeeder::class);
    }
}
