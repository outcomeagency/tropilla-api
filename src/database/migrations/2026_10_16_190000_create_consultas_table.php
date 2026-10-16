<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-49/55 — consultas de compradores (modelo-datos §9.6). Datos personales: solo dueño/admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('origen');
            $table->foreignUuid('oferta_id')->nullable()->constrained('ofertas')->restrictOnDelete();
            $table->uuid('contrato_id')->nullable();
            $table->text('nombre');
            $table->text('empresa')->nullable();
            $table->char('pais', 2)->nullable();
            $table->text('telefono')->nullable();
            $table->text('tipo_comprador')->nullable();
            $table->text('volumen')->nullable();
            $table->text('mensaje');
            $table->text('idioma')->default('es');
            $table->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->text('estado')->default('nueva');
            $table->timestampTz('vista_en')->nullable();
            $table->timestampTz('respondida_en')->nullable();
            $table->foreignUuid('respondida_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->text('canal_respuesta')->nullable();
            $table->timestampTz('cerrada_en')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->decimal('captcha_score', 3, 2)->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE consultas ADD COLUMN email citext NULL');
        DB::statement('CREATE INDEX consultas_bandeja ON consultas (establecimiento_id, estado, created_at DESC)');
        DB::statement("ALTER TABLE consultas ADD CONSTRAINT consultas_estado CHECK (estado IN ('nueva','respondida','cerrada','spam'))");
        DB::statement("ALTER TABLE consultas ADD CONSTRAINT consultas_origen CHECK (origen IN ('perfil','oferta','contrato','directorio'))");
        DB::statement("ALTER TABLE consultas ADD CONSTRAINT consultas_idioma CHECK (idioma IN ('es','en','pt'))");
        DB::statement('ALTER TABLE consultas ADD CONSTRAINT consultas_mensaje CHECK (length(mensaje) <= 3000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas');
    }
};
