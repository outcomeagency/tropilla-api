<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-15 — Catálogos y parámetros por especie (modelo-datos.md §3): reglas como datos.
 * Catálogos referenciados por `codigo` estable (M2); globales con establecimiento_id NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('especies', function (Blueprint $table) {
            $table->text('codigo')->primary();
            $table->jsonb('nombres');
            $table->text('nombre_cientifico')->nullable();
            $table->boolean('activa')->default(true);
            $table->smallInteger('orden')->default(0);
        });

        Schema::create('razas', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->text('codigo');
            $table->text('especie_codigo');
            $table->jsonb('nombres');
            $table->char('prefijo_tatuaje', 1)->nullable();
            $table->foreignUuid('establecimiento_id')->nullable()->constrained('establecimientos')->restrictOnDelete();
            $table->boolean('activa')->default(true);
            $table->smallInteger('orden')->default(0);
            $table->foreign('especie_codigo')->references('codigo')->on('especies')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX razas_codigo_unico ON razas (establecimiento_id, codigo) NULLS NOT DISTINCT');

        Schema::create('categorias', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->text('codigo')->unique();
            $table->text('especie_codigo');
            $table->jsonb('nombres');
            $table->char('sexo', 1)->nullable();
            $table->boolean('castrado')->nullable();
            $table->boolean('es_cria')->default(false);
            $table->boolean('es_vientre')->default(false);
            $table->boolean('es_reproductor')->default(false);
            $table->smallInteger('orden')->default(0);
            $table->boolean('activa')->default(true);
            $table->foreign('especie_codigo')->references('codigo')->on('especies')->restrictOnDelete();
        });

        Schema::create('categorias_faena', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->text('codigo')->unique();
            $table->text('especie_codigo');
            $table->text('abreviatura');
            $table->jsonb('nombres');
            $table->char('sexo', 1)->nullable();
            $table->text('denticion_desde')->nullable();
            $table->text('denticion_hasta')->nullable();
            $table->decimal('liviano_hasta_kg', 7, 1)->nullable();
            $table->text('norma')->nullable();
            $table->foreign('especie_codigo')->references('codigo')->on('especies')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE categorias_faena ADD COLUMN categorias_manejo text[] NOT NULL DEFAULT '{}'");

        Schema::create('reglas_categoria', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->text('especie_codigo');
            $table->foreignUuid('establecimiento_id')->nullable()->constrained('establecimientos')->restrictOnDelete();
            $table->text('categoria_codigo');
            $table->smallInteger('prioridad');
            $table->jsonb('condiciones');
            $table->text('motivo');
            $table->boolean('activa')->default(true);
            $table->integer('version')->default(1);
            $table->foreign('especie_codigo')->references('codigo')->on('especies')->restrictOnDelete();
            $table->foreign('categoria_codigo')->references('codigo')->on('categorias')->restrictOnDelete();
            $table->index(['especie_codigo', 'prioridad']);
        });

        Schema::create('parametros', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->text('clave');
            $table->text('especie_codigo')->nullable();
            $table->text('raza_codigo')->nullable();
            $table->foreignUuid('establecimiento_id')->nullable()->constrained('establecimientos')->restrictOnDelete();
            $table->jsonb('valor')->nullable();
            $table->text('unidad')->nullable();
            $table->text('fuente');
            $table->boolean('validado_por_vet')->default(false);
            $table->date('vigente_desde');
            $table->timestampTz('created_at')->useCurrent();
            $table->index('clave');
        });
        DB::statement('CREATE UNIQUE INDEX parametros_unico ON parametros (clave, especie_codigo, raza_codigo, establecimiento_id, vigente_desde) NULLS NOT DISTINCT');

        Schema::create('tipos_evento', function (Blueprint $table) {
            $table->text('codigo')->primary();
            $table->text('modulo');
            $table->jsonb('nombres');
            $table->text('fase');
            $table->boolean('activo')->default(true);
            $table->boolean('crea_animales')->default(false);
            $table->char('requiere_sexo', 1)->nullable();
            $table->text('icono')->nullable();
            $table->smallInteger('orden')->default(0);
        });
        DB::statement("ALTER TABLE tipos_evento ADD COLUMN sujetos_admitidos text[] NOT NULL DEFAULT '{}'");

        Schema::create('tipos_evento_versiones', function (Blueprint $table) {
            $table->text('tipo_codigo');
            $table->smallInteger('version');
            $table->jsonb('json_schema');
            $table->date('vigente_desde');
            $table->primary(['tipo_codigo', 'version']);
            $table->foreign('tipo_codigo')->references('codigo')->on('tipos_evento')->restrictOnDelete();
        });

        Schema::create('tipos_documento', function (Blueprint $table) {
            $table->text('codigo')->primary();
            $table->jsonb('nombres');
            $table->text('privacidad_default');
            $table->boolean('publicable')->default(true);
            $table->integer('vence_por_defecto_dias')->nullable();
            $table->text('aviso')->nullable();
            $table->smallInteger('orden')->default(0);
        });
        DB::statement("ALTER TABLE tipos_documento ADD CONSTRAINT tipos_documento_privacidad CHECK (privacidad_default IN ('fiscal','sanitario','comercial','general'))");

        Schema::create('tipos_prueba', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->text('codigo')->unique();
            $table->jsonb('nombres');
            $table->text('metodo_calculo');
            $table->jsonb('umbrales')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('validado_por_vet')->default(false);
        });
        DB::statement("ALTER TABLE tipos_prueba ADD COLUMN especies text[] NOT NULL DEFAULT '{}'");

        Schema::create('catalogo_items', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->text('lista');
            $table->text('codigo');
            $table->jsonb('nombres');
            $table->foreignUuid('establecimiento_id')->nullable()->constrained('establecimientos')->restrictOnDelete();
            $table->smallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->index('lista');
        });
        DB::statement('ALTER TABLE catalogo_items ADD COLUMN especies text[] NULL');
        DB::statement('CREATE UNIQUE INDEX catalogo_items_unico ON catalogo_items (lista, establecimiento_id, codigo) NULLS NOT DISTINCT');

        Schema::create('plantillas_sanitarias', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->text('codigo')->unique();
            $table->text('especie_codigo')->nullable();
            $table->jsonb('nombres');
            $table->text('tipo_evento');
            $table->text('enfermedad')->nullable();
            $table->text('objetivo')->nullable();
            $table->jsonb('alcance')->default('{}');
            $table->jsonb('disparador')->default('{}');
            $table->jsonb('repeticion')->nullable();
            $table->jsonb('condicion')->nullable();
            $table->text('nota')->nullable();
            $table->text('aviso');
            $table->text('fuente')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('activa')->default(true);
        });

        Schema::create('catalogo_versiones', function (Blueprint $table) {
            $table->id('version')->generatedAs()->always();
            $table->text('motivo');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['catalogo_versiones', 'plantillas_sanitarias', 'catalogo_items', 'tipos_prueba', 'tipos_documento',
            'tipos_evento_versiones', 'tipos_evento', 'parametros', 'reglas_categoria', 'categorias_faena',
            'categorias', 'razas', 'especies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
