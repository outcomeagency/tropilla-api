<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-33 — modelo-datos.md §7: `planes_sanitarios` (aviso veterinario aceptado: quién y cuándo), `plan_items`
 * (maestro, puede venir de una plantilla) y `tareas` (maestro; idempotencia del generador por (plan_item_id, clave_ocurrencia)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes_sanitarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->unique()->constrained('establecimientos')->restrictOnDelete();
            $table->timestampTz('aviso_aceptado_en')->nullable();
            $table->foreignUuid('aviso_aceptado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('plan_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('planes_sanitarios')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('plantilla_codigo')->nullable();
            $table->integer('plantilla_version')->nullable();
            $table->text('nombre');
            $table->text('especie_codigo')->nullable();
            $table->text('tipo_evento');
            $table->text('enfermedad')->nullable();
            $table->foreignUuid('producto_id')->nullable()->constrained('productos_sanitarios')->restrictOnDelete();
            $table->jsonb('alcance')->default('{}');
            $table->jsonb('disparador');
            $table->jsonb('repeticion')->nullable();
            $table->jsonb('condicion')->nullable();
            $table->text('nota')->nullable();
            $table->boolean('activo')->default(true);
            $table->boolean('editado')->default(false);
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->timestampTz('archivado_en')->nullable();
            $table->foreignUuid('archivado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->index('establecimiento_id');
        });

        Schema::create('tareas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('plan_item_id')->nullable()->constrained('plan_items')->restrictOnDelete();
            $table->text('clave_ocurrencia')->nullable();
            $table->text('titulo');
            $table->text('tipo_evento');
            $table->text('enfermedad')->nullable();
            $table->foreignUuid('producto_id')->nullable()->constrained('productos_sanitarios')->restrictOnDelete();
            $table->jsonb('alcance')->default('{}');
            $table->date('fecha_desde')->nullable();
            $table->date('fecha_hasta')->nullable();
            $table->date('vence_en');
            $table->text('estado')->default('pendiente');
            $table->uuid('evento_raiz_id')->nullable();
            $table->uuid('origen_evento_id')->nullable();
            $table->integer('cabezas_estimadas')->nullable();
            $table->boolean('reprogramada')->default(false);
            $table->text('omitida_motivo')->nullable();
            $table->foreignUuid('omitida_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('omitida_en')->nullable();
            $table->timestampTz('hecha_en')->nullable();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->unique(['plan_item_id', 'clave_ocurrencia']);
            $table->index(['establecimiento_id', 'estado', 'vence_en']);
        });
        DB::statement("ALTER TABLE tareas ADD CONSTRAINT tareas_estado CHECK (estado IN ('pendiente','hecha','omitida'))");
        DB::statement('ALTER TABLE tareas ADD CONSTRAINT tareas_ventana CHECK (fecha_desde IS NULL OR fecha_hasta IS NULL OR fecha_desde <= fecha_hasta)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tareas');
        Schema::dropIfExists('plan_items');
        Schema::dropIfExists('planes_sanitarios');
    }
};
