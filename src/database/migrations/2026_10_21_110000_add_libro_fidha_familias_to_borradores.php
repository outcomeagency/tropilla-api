<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TPA-65 — Libro de Registro de Tratamientos (Res. 666/2011) y FIDHA (Res. 655/2026) como familias de lectura asistida.
 * Aditiva: CHECK de familias + tipos de documento `libro_tratamientos` y `fidha` (también en `TiposYListasSeeder`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->familias(fn (array $f) => array_values(array_unique([...$f, 'libro_tratamientos', 'fidha'])));
        DB::table('tipos_documento')->insertOrIgnore([
            ['codigo' => 'libro_tratamientos', 'nombres' => json_encode(['es' => 'Libro de registro de tratamientos', 'en' => 'Treatment record book', 'pt' => 'Livro de registro de tratamentos'], JSON_UNESCAPED_UNICODE),
                'privacidad_default' => 'sanitario', 'publicable' => false, 'orden' => 6],
            ['codigo' => 'fidha', 'nombres' => json_encode(['es' => 'FIDHA (inspección de garrapata)', 'en' => 'Tick inspection form (FIDHA)', 'pt' => 'FIDHA (inspeção de carrapato)'], JSON_UNESCAPED_UNICODE),
                'privacidad_default' => 'sanitario', 'publicable' => true, 'orden' => 6],
        ]);
    }

    public function down(): void
    {
        $this->familias(fn (array $f) => array_values(array_diff($f, ['libro_tratamientos', 'fidha'])));
    }

    /**
     * Rehace el CHECK de familias a partir del vigente: otras migraciones (p. ej. TPA-66) suman las suyas y el orden en
     * que corren en cada ambiente puede variar.
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
