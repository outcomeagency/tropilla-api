<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-63 — importación de identificaciones electrónicas (lector/bastón, archivo CSV del lector, planilla de caravanas).
 * La arma el teléfono sin señal (UUIDv7) y la sube cuando hay señal; las colocaciones viajan como eventos
 * `hacienda.identificacion` por la outbox. Acá queda **cada fila leída**, incluidas las duplicadas, inválidas y sin
 * conciliar: nunca se borran (un PUT con menos filas se rechaza). `estado` = lo que decidió el teléfono;
 * `estado_servidor` = la reverificación del servidor al recibirla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identificacion_importaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('especie_codigo');
            $table->date('fecha');
            $table->text('motivo');
            $table->text('origen');
            $table->text('dispositivo_tipo')->nullable();
            $table->text('lector')->nullable();
            $table->text('archivo_nombre')->nullable();
            $table->uuid('documento_id')->nullable(); // foto de la planilla (viaja por la outbox de documentos)
            $table->text('estado');
            $table->jsonb('resumen')->default('{}');
            $table->jsonb('reglas')->default('[]');
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->uuid('dispositivo_id')->nullable();
            $table->timestampTz('confirmada_en')->nullable();
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'created_at']);
        });
        DB::statement("ALTER TABLE identificacion_importaciones ADD CONSTRAINT identificacion_importaciones_estado CHECK (estado IN ('abierta','confirmada'))");
        DB::statement("ALTER TABLE identificacion_importaciones ADD CONSTRAINT identificacion_importaciones_especie CHECK (especie_codigo IN ('BOV','BUB'))");

        Schema::create('identificacion_importacion_filas', function (Blueprint $table) {
            $table->foreignUuid('importacion_id')->constrained('identificacion_importaciones')->restrictOnDelete();
            $table->integer('numero');
            $table->text('rfid')->nullable();
            $table->text('visual')->nullable();
            $table->text('leido')->nullable(); // línea cruda del lector/archivo
            $table->text('origen');
            $table->date('fecha')->nullable();
            $table->uuid('animal_id')->nullable();
            $table->text('estado');
            $table->text('estado_servidor');
            $table->jsonb('mensajes')->default('[]');
            $table->uuid('evento_id')->nullable();
            $table->primary(['importacion_id', 'numero']);
            $table->index('animal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identificacion_importacion_filas');
        Schema::dropIfExists('identificacion_importaciones');
    }
};
