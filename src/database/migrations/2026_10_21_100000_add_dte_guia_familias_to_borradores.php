<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-64 — DT-e y guía provincial (DUT Chaco) como familias de lectura asistida. Aditiva:
 * - `eventos_vinculados`: movimientos ya cargados a los que la confirmación vinculó el documento (no se crean eventos).
 * - CHECK de familias + tipo de documento `guia_provincial` (también en `TiposYListasSeeder`, idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borradores_documento', function ($table) {
            $table->jsonb('eventos_vinculados')->default('[]');
        });
        DB::statement('ALTER TABLE borradores_documento DROP CONSTRAINT borradores_documento_familia');
        DB::statement("ALTER TABLE borradores_documento ADD CONSTRAINT borradores_documento_familia CHECK (familia IN ('acta_vacunacion','dte','guia_provincial'))");
        DB::table('tipos_documento')->insertOrIgnore([
            'codigo' => 'guia_provincial', 'nombres' => json_encode(['es' => 'Guía provincial / DUT', 'en' => 'Provincial livestock guide', 'pt' => 'Guia provincial'], JSON_UNESCAPED_UNICODE),
            'privacidad_default' => 'comercial', 'publicable' => false, 'orden' => 8,
        ]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE borradores_documento DROP CONSTRAINT borradores_documento_familia');
        DB::statement("ALTER TABLE borradores_documento ADD CONSTRAINT borradores_documento_familia CHECK (familia IN ('acta_vacunacion')) NOT VALID");
        Schema::table('borradores_documento', fn ($table) => $table->dropColumn('eventos_vinculados'));
    }
};
