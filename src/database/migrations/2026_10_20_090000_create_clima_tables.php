<?php

use App\Domain\Clima\ReglasPredeterminadas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-70 — Alertas climáticas por establecimiento.
 * `clima_reglas`: umbrales como datos (fila global + override opcional por establecimiento).
 * `clima_alertas`: alertas calculadas (con fuente, zona, horizonte y umbral); no se borran, se descartan si el pronóstico cambia.
 * `clima_estado`: último cálculo por establecimiento (resumen del pronóstico, anomalía de lluvia, ENSO y errores de fuentes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clima_reglas', function (Blueprint $table) {
            $table->id();
            $table->text('codigo');
            $table->foreignUuid('establecimiento_id')->nullable()->constrained('establecimientos')->restrictOnDelete();
            $table->text('tipo');
            $table->text('nombre');
            $table->text('severidad');
            $table->jsonb('parametros')->default('{}');
            $table->text('recomendacion');
            $table->boolean('activa')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX clima_reglas_unica ON clima_reglas (codigo, establecimiento_id) NULLS NOT DISTINCT');
        DB::statement("ALTER TABLE clima_reglas ADD CONSTRAINT clima_reglas_severidad CHECK (severidad IN ('info','moderada','alta'))");

        Schema::create('clima_alertas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('regla_codigo');
            $table->text('tipo');
            $table->text('severidad');
            $table->text('titulo');
            $table->text('detalle');
            $table->text('recomendacion');
            $table->text('fuente');
            $table->text('fuente_url');
            $table->text('zona');
            $table->decimal('valor', 10, 2)->nullable();
            $table->decimal('umbral', 10, 2)->nullable();
            $table->text('unidad')->nullable();
            $table->date('desde');
            $table->date('hasta');
            $table->integer('horizonte_dias');
            $table->text('estado')->default('activa');
            $table->timestampTz('emitida_en');
            $table->timestampTz('calculada_en');
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'estado', 'hasta']);
        });
        DB::statement("ALTER TABLE clima_alertas ADD CONSTRAINT clima_alertas_estado CHECK (estado IN ('activa','descartada'))");

        Schema::create('clima_estado', function (Blueprint $table) {
            $table->foreignUuid('establecimiento_id')->primary()->constrained('establecimientos')->restrictOnDelete();
            $table->text('precision');
            $table->text('zona');
            $table->jsonb('pronostico')->default('[]');
            $table->jsonb('lluvia')->nullable();
            $table->jsonb('enso')->nullable();
            $table->jsonb('errores')->default('[]');
            $table->timestampTz('calculado_en');
            $table->timestampsTz();
        });

        foreach (ReglasPredeterminadas::TODAS as $r) {
            DB::table('clima_reglas')->insertOrIgnore([...$r, 'parametros' => json_encode($r['parametros']), 'activa' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clima_estado');
        Schema::dropIfExists('clima_alertas');
        Schema::dropIfExists('clima_reglas');
    }
};
