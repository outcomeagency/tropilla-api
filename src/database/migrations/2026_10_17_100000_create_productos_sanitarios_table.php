<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-31 — modelo-datos.md §7 `productos_sanitarios` (maestro sincronizable; el id puede venir del teléfono).
 * `dosis_declarada` es texto del productor/vet: TROPILLA no prescribe dosis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos_sanitarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('nombre_comercial');
            $table->text('principio_activo')->nullable();
            $table->text('tipo');
            $table->text('via')->nullable();
            $table->text('dosis_declarada')->nullable();
            $table->integer('retiro_carne_dias')->nullable();
            $table->integer('retiro_leche_dias')->nullable();
            $table->text('notas')->nullable();
            $table->text('sugerido_codigo')->nullable();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->timestampTz('archivado_en')->nullable();
            $table->foreignUuid('archivado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->index('establecimiento_id');
        });
        DB::statement("ALTER TABLE productos_sanitarios ADD COLUMN especies text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE productos_sanitarios ADD COLUMN enfermedades text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE productos_sanitarios ADD CONSTRAINT productos_sanitarios_tipo CHECK (tipo IN ('vacuna','antiparasitario_interno','antiparasitario_externo','antibiotico','hormona','otro'))");
        DB::statement('ALTER TABLE productos_sanitarios ADD CONSTRAINT productos_sanitarios_retiros CHECK (coalesce(retiro_carne_dias, 0) >= 0 AND coalesce(retiro_leche_dias, 0) >= 0)');
        DB::statement('CREATE UNIQUE INDEX productos_sanitarios_nombre_unico ON productos_sanitarios (establecimiento_id, lower(nombre_comercial)) WHERE archivado_en IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('productos_sanitarios');
    }
};
