<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-82 — directorio del ecosistema, leads y reseñas vinculadas a operaciones registradas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubros_proveedor', function (Blueprint $table) {
            $table->id();
            $table->text('codigo')->unique();
            $table->jsonb('nombres');
            $table->boolean('activo')->default(true);
        });
        Schema::create('proveedores', function (Blueprint $table) {
            $table->foreignUuid('usuario_id')->primary()->constrained('usuarios')->restrictOnDelete();
            $table->text('slug')->unique();
            $table->text('nombre_publico');
            $table->text('razon_social_declarada')->nullable();
            $table->text('razon_social_informada')->nullable();
            $table->text('cuit_cifrado');
            $table->char('cuit_hash', 64)->unique();
            $table->text('estado_cuit')->default('pendiente');
            $table->text('fuente_cuit')->nullable();
            $table->timestampTz('validado_en')->nullable();
            $table->jsonb('descripciones')->default('{}');
            $table->jsonb('idiomas')->default('["es"]');
            $table->jsonb('provincias')->default('[]');
            $table->boolean('publico')->default(false);
            $table->boolean('patrocinado')->default(false);
            $table->boolean('es_demo')->default(false);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE proveedores ADD COLUMN cobertura geography(MultiPolygon,4326) NULL');
        DB::statement('CREATE INDEX proveedores_cobertura_gix ON proveedores USING GIST (cobertura)');
        DB::statement("ALTER TABLE proveedores ADD CONSTRAINT proveedores_estado_cuit CHECK (estado_cuit IN ('pendiente','inconsistente','validado'))");
        Schema::create('proveedor_rubros', function (Blueprint $table) {
            $table->foreignUuid('proveedor_id')->constrained('proveedores', 'usuario_id')->restrictOnDelete();
            $table->foreignId('rubro_id')->constrained('rubros_proveedor')->restrictOnDelete();
            $table->primary(['proveedor_id', 'rubro_id']);
        });
        Schema::create('leads_proveedor', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('proveedor_id')->constrained('proveedores', 'usuario_id')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('iniciado_por')->constrained('usuarios')->restrictOnDelete();
            $table->text('rubro_codigo');
            $table->text('mensaje');
            $table->text('estado')->default('nuevo');
            $table->timestampsTz();
            $table->index(['proveedor_id', 'created_at']);
        });
        Schema::create('resenas_proveedor', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('proveedor_id')->constrained('proveedores', 'usuario_id')->restrictOnDelete();
            $table->foreignUuid('operacion_id')->constrained('operaciones')->restrictOnDelete();
            $table->foreignUuid('autor_id')->constrained('usuarios')->restrictOnDelete();
            $table->unsignedTinyInteger('puntaje');
            $table->text('comentario')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['proveedor_id', 'operacion_id']);
        });
        DB::statement('ALTER TABLE resenas_proveedor ADD CONSTRAINT resenas_puntaje CHECK (puntaje BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE operaciones ADD COLUMN proveedor_id uuid NULL REFERENCES proveedores(usuario_id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE operaciones DROP COLUMN IF EXISTS proveedor_id');
        Schema::dropIfExists('resenas_proveedor');
        Schema::dropIfExists('leads_proveedor');
        Schema::dropIfExists('proveedor_rubros');
        Schema::dropIfExists('proveedores');
        Schema::dropIfExists('rubros_proveedor');
    }
};
