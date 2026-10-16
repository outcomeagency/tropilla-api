<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeders idempotentes: el entrypoint los corre en cada arranque.
 * Usar updateOrCreate / firstOrCreate, nunca create a secas.
 * Catálogos por especie (reglas como datos) se agregan acá en sus tickets.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (app()->environment('local')) {
            User::firstOrCreate(
                ['email' => 'dev@tropilla.local'],
                ['name' => 'Dev TROPILLA', 'password' => 'password'],
            );
        }
    }
}
