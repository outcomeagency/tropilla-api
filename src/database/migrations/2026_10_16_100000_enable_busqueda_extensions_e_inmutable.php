<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * modelo-datos.md §1.2 y §1.4.
 *
 * - citext:   emails case-insensitive (usuarios, invitaciones, consultas).
 * - pg_trgm:  búsqueda por identificador parcial y directorio.
 * - unaccent: directorio.
 * - tropilla_inmutable(): función de trigger para las tablas append-only
 *   (eventos, documento_versiones, registros_integridad, cambios, auditoria, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tropilla_inmutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'TROPILLA: % es append-only (% bloqueado)', TG_TABLE_NAME, TG_OP
                USING ERRCODE = 'insufficient_privilege';
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS tropilla_inmutable() CASCADE');
    }
};
