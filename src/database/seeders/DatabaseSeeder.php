<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeders idempotentes: el entrypoint los corre en cada arranque.
 * Usar updateOrCreate / firstOrCreate, nunca create a secas.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(CatalogosSeeder::class);
        $this->call(ContraparteReglasSeeder::class);

        if (app()->environment('local')) {
            User::firstOrCreate(
                ['email' => 'dev@tropilla.local'],
                ['nombre' => 'Dev TROPILLA', 'password' => 'password', 'email_verificado_en' => now()],
            );
            // Demo «La Esperanza» (demo@tropilla.ar / tropilla-demo) para el front y la app. Idempotente.
            // Ficha regional, directorio y datos sintéticos ampliados; seguro para repetir y para producción con --force.
            $this->call(DemoRegionalSeeder::class);
        }
    }
}
