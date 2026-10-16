<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-58 — modelo-datos §9.7 y §2.9: «Busco» (demandas de compradores), respuestas, coincidencias (proyección) y perfil de profesional. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buscos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('comprador_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('especie_codigo');
            $table->integer('cantidad');
            $table->decimal('peso_objetivo_kg', 7, 1)->nullable();
            $table->jsonb('zona');
            $table->date('plazo_hasta')->nullable();
            $table->text('mensaje')->nullable();
            $table->boolean('mostrar_nombre')->default(false);
            $table->text('estado')->default('abierta');
            $table->timestampTz('cerrada_en')->nullable();
            $table->timestampsTz();
            $table->index(['estado', 'plazo_hasta']);
        });
        DB::statement("ALTER TABLE buscos ADD COLUMN categorias text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE buscos ADD COLUMN razas text[] NOT NULL DEFAULT '{}'");
        DB::statement('CREATE INDEX buscos_categorias ON buscos USING gin (categorias)');
        DB::statement("ALTER TABLE buscos ADD CONSTRAINT buscos_estado CHECK (estado IN ('abierta','cerrada','vencida'))");

        Schema::create('busco_respuestas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('busco_id')->constrained('buscos')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('autor_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('mensaje');
            $table->uuid('oferta_id')->nullable();
            $table->timestampTz('leida_en')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('busco_id');
        });

        Schema::create('busco_coincidencias', function (Blueprint $table) {
            $table->foreignUuid('busco_id')->constrained('buscos')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->smallInteger('puntaje');
            $table->jsonb('motivo');
            $table->timestampTz('notificado_en')->nullable();
            $table->primary(['busco_id', 'establecimiento_id']);
            $table->index('establecimiento_id');
        });

        Schema::create('profesionales', function (Blueprint $table) {
            $table->foreignUuid('usuario_id')->primary()->constrained('usuarios')->restrictOnDelete();
            $table->text('slug')->unique();
            $table->text('nombre_publico');
            $table->text('profesion');
            $table->text('matricula_declarada')->nullable();
            $table->jsonb('zonas')->default('{}');
            $table->text('bio')->nullable();
            $table->char('foto_sha256', 64)->nullable();
            $table->boolean('publico')->default(false);
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE profesionales ADD COLUMN especialidades text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE profesionales ADD COLUMN idiomas text[] NOT NULL DEFAULT '{es}'");
        DB::statement("ALTER TABLE profesionales ADD CONSTRAINT profesionales_profesion CHECK (profesion IN ('veterinario','ing_agronomo','asesor','otro'))");

        Schema::create('profesional_establecimientos', function (Blueprint $table) {
            $table->foreignUuid('profesional_id')->constrained('profesionales', 'usuario_id')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('autorizado_por')->constrained('usuarios')->restrictOnDelete();
            $table->boolean('visible_en_perfil')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('revocado_en')->nullable();
            $table->primary(['profesional_id', 'establecimiento_id']);
        });
    }

    public function down(): void
    {
        foreach (['profesional_establecimientos', 'profesionales', 'busco_coincidencias', 'busco_respuestas', 'buscos'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
