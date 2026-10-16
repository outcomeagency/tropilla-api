<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensiones de PostgreSQL desde el día 1 (corre antes que cualquier tabla).
 *
 * - postgis:  potreros, ubicación de establecimientos, EUDR (fase 3).
 * - pgcrypto: digest()/SHA-256 del lado de la base para verificaciones de la
 *             cadena de hashes. Los UUIDv7 los generan la app y el dispositivo
 *             (gen_random_uuid() de PG16 es v4 y queda solo como fallback).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS pgcrypto');
        DB::statement('DROP EXTENSION IF EXISTS postgis');
    }
};
