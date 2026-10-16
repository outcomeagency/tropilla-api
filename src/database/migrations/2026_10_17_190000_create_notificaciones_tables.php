<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-53 — modelo-datos §12: notificaciones in-app (+ push/email) con dedupe y preferencias por usuario/establecimiento/tipo. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->nullable()->constrained('establecimientos')->restrictOnDelete();
            $table->text('tipo');
            $table->text('titulo');
            $table->text('cuerpo');
            $table->jsonb('datos')->default('{}');
            $table->text('canal')->default('in_app');
            $table->text('clave_dedupe')->unique();
            $table->timestampTz('enviada_en')->nullable();
            $table->timestampTz('email_en')->nullable();
            $table->timestampTz('leida_en')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['usuario_id', 'leida_en', 'created_at']);
        });

        Schema::create('preferencias_notificacion', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->nullable()->constrained('establecimientos')->restrictOnDelete();
            $table->text('tipo');
            $table->boolean('push')->default(true);
            $table->boolean('email')->default(false);
            $table->boolean('resumen_diario')->default(false);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX preferencias_notificacion_unica ON preferencias_notificacion (usuario_id, establecimiento_id, tipo) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('preferencias_notificacion');
        Schema::dropIfExists('notificaciones');
    }
};
