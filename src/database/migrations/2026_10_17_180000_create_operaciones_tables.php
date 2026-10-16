<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-46/47 — modelo-datos §9.2/9.3: `operaciones` (cabecera = proyección de la versión vigente, para «Mis precios»),
 * `operacion_versiones` (append-only, sobre `tropilla.operacion_version/1` hasheado y encadenado), `operacion_animales`
 * (aditivo: compromiso «vendido» de los animales del trato) y `precios_referencia` (inmutables; los usa TPA-47).
 * Precios **siempre privados**: nada de esto viaja por sync ni a lo público.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precios_referencia', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('descripcion');
            $table->text('especie_codigo');
            $table->text('categoria_codigo')->nullable();
            $table->text('modalidad');
            $table->decimal('precio_kg', 14, 4);
            $table->text('moneda')->default('ARS');
            $table->date('fecha');
            $table->text('fuente')->nullable();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['establecimiento_id', 'especie_codigo', 'fecha']);
        });
        DB::statement('CREATE TRIGGER precios_referencia_inmutable BEFORE UPDATE OR DELETE ON precios_referencia FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');

        Schema::create('operaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('tipo');
            $table->text('estado')->default('registrada');
            $table->integer('version_vigente');
            $table->uuid('evento_raiz_id')->nullable();
            $table->uuid('simulacion_id')->nullable();
            $table->uuid('contrato_id')->nullable();
            $table->uuid('oferta_id')->nullable();
            $table->text('especie_codigo');
            $table->text('categoria_codigo');
            $table->integer('cantidad');
            $table->date('fecha');
            $table->text('contraparte')->nullable();
            $table->text('destino')->nullable();
            $table->text('modalidad');
            $table->decimal('precio_kg', 14, 4);
            $table->text('moneda')->default('ARS');
            $table->decimal('neto_total', 14, 2);
            $table->decimal('neto_kg_vivo', 14, 4);
            $table->foreignUuid('precio_referencia_id')->nullable()->constrained('precios_referencia')->restrictOnDelete();
            $table->decimal('diferencial_pct', 6, 2)->nullable();
            $table->uuid('documento_id')->nullable();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE operaciones ADD CONSTRAINT operaciones_tipo CHECK (tipo IN ('venta','compra'))");
        DB::statement("ALTER TABLE operaciones ADD CONSTRAINT operaciones_estado CHECK (estado IN ('registrada','anulada'))");
        DB::statement("ALTER TABLE operaciones ADD CONSTRAINT operaciones_modalidad CHECK (modalidad IN ('en_pie','al_gancho'))");
        DB::statement('CREATE INDEX operaciones_historial ON operaciones (establecimiento_id, especie_codigo, categoria_codigo, fecha DESC)');

        Schema::create('operacion_versiones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operacion_id')->constrained('operaciones')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->integer('version');
            $table->text('accion');
            $table->text('motivo')->nullable();
            $table->jsonb('trato');
            $table->jsonb('liquidacion');
            $table->jsonb('trazabilidad')->default('{}');
            $table->char('pdf_sha256', 64)->nullable();
            $table->jsonb('sobre');
            $table->char('hash', 64);
            $table->char('hash_anterior', 64)->nullable();
            $table->unsignedBigInteger('registro_integridad_id');
            $table->foreign('registro_integridad_id')->references('id')->on('registros_integridad')->restrictOnDelete();
            $table->foreignUuid('autor_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['operacion_id', 'version']);
        });
        DB::statement("ALTER TABLE operacion_versiones ADD CONSTRAINT operacion_versiones_accion CHECK (accion IN ('alta','correccion','anulacion'))");
        DB::statement('CREATE TRIGGER operacion_versiones_inmutable BEFORE UPDATE OR DELETE ON operacion_versiones FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
        DB::statement('CREATE TRIGGER operacion_versiones_no_truncate BEFORE TRUNCATE ON operacion_versiones FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()');

        Schema::create('operacion_animales', function (Blueprint $table) {
            $table->foreignUuid('operacion_id')->constrained('operaciones')->restrictOnDelete();
            $table->foreignUuid('animal_id')->constrained('animales')->restrictOnDelete();
            $table->primary(['operacion_id', 'animal_id']);
            $table->index('animal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operacion_animales');
        Schema::dropIfExists('operacion_versiones');
        Schema::dropIfExists('operaciones');
        Schema::dropIfExists('precios_referencia');
    }
};
