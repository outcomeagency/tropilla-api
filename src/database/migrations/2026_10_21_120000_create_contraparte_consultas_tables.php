<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-72 — Chequeo de contraparte por CUIT.
 * `contraparte_consultas`: registro de cada consulta (quién, para qué, con qué consentimiento) y el informe obtenido. No se edita;
 * vencida la retención, `contrapartes:depurar` borra el informe y deja el registro de auditoría (`depurada_en`).
 * `contraparte_checklist`: diligencias manuales del productor sobre esa consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contraparte_consultas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->char('cuit', 11);
            $table->text('tipo_persona');
            $table->text('denominacion')->nullable();
            $table->text('finalidad');
            $table->text('finalidad_detalle')->nullable();
            $table->jsonb('consentimiento');
            $table->jsonb('informe')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('depurada_en')->nullable();
            $table->index(['establecimiento_id', 'created_at']);
        });
        DB::statement("ALTER TABLE contraparte_consultas ADD CONSTRAINT contraparte_consultas_tipo CHECK (tipo_persona IN ('humana','juridica'))");

        Schema::create('contraparte_checklist', function (Blueprint $table) {
            $table->foreignUuid('consulta_id')->constrained('contraparte_consultas')->restrictOnDelete();
            $table->text('item');
            $table->text('estado');
            $table->text('nota')->nullable();
            $table->foreignUuid('actualizado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('updated_at');
            $table->primary(['consulta_id', 'item']);
        });
        DB::statement("ALTER TABLE contraparte_checklist ADD CONSTRAINT contraparte_checklist_estado CHECK (estado IN ('pendiente','hecho','no_aplica'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contraparte_checklist');
        Schema::dropIfExists('contraparte_consultas');
    }
};
