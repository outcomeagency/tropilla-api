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
        $this->familias(fn (array $f) => array_values(array_unique([...$f, 'dte', 'guia_provincial'])));
        DB::table('tipos_documento')->insertOrIgnore([
            'codigo' => 'guia_provincial', 'nombres' => json_encode(['es' => 'Guía provincial / DUT', 'en' => 'Provincial livestock guide', 'pt' => 'Guia provincial'], JSON_UNESCAPED_UNICODE),
            'privacidad_default' => 'comercial', 'publicable' => false, 'orden' => 8,
        ]);
    }

    public function down(): void
    {
        $this->familias(fn (array $f) => array_values(array_diff($f, ['dte', 'guia_provincial'])));
        Schema::table('borradores_documento', fn ($table) => $table->dropColumn('eventos_vinculados'));
    }

    /**
     * Rehace el CHECK de familias a partir del vigente: otras migraciones suman las suyas y el orden en que corren en
     * cada ambiente puede variar.
     *
     * @param  callable(list<string>): list<string>  $cambio
     */
    private function familias(callable $cambio): void
    {
        $def = (string) DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'borradores_documento_familia'");
        preg_match_all("/'([a-z0-9_]+)'/", $def, $m);
        DB::statement('ALTER TABLE borradores_documento DROP CONSTRAINT IF EXISTS borradores_documento_familia');
        DB::statement('ALTER TABLE borradores_documento ADD CONSTRAINT borradores_documento_familia CHECK (familia IN ('
            .implode(',', array_map(fn ($f) => "'{$f}'", $cambio($m[1]))).')) NOT VALID');
    }
};
