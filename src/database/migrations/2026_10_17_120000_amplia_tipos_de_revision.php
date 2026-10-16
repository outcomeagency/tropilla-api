<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TPA-34 — amplía (solo agrega valores) los tipos de revisión: `resultado_prueba_discrepante` (un resultado calculado offline con
 * umbrales que cambiaron). Aditivo: ninguna fila existente deja de cumplir.
 */
return new class extends Migration
{
    private const BASE = ['correccion_en_conflicto', 'documento_en_conflicto', 'maestro_en_conflicto', 'identificacion_duplicada',
        'evento_posterior_a_baja', 'conteo_negativo', 'carga_post_revocacion'];

    public function up(): void
    {
        $this->constraint([...self::BASE, 'resultado_prueba_discrepante']);
    }

    public function down(): void
    {
        $this->constraint(self::BASE);
    }

    /** @param list<string> $tipos */
    private function constraint(array $tipos): void
    {
        DB::statement('ALTER TABLE revisiones DROP CONSTRAINT IF EXISTS revisiones_tipo');
        DB::statement("ALTER TABLE revisiones ADD CONSTRAINT revisiones_tipo CHECK (tipo IN ('".implode("','", $tipos)."'))");
    }
};
