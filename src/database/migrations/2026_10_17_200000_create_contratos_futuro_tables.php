<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-56/57 — modelo-datos §9.5: contratos a futuro con token. Cabecera `contratos_futuro` (términos vigentes desnormalizados;
 * `terminos_borrador` mientras no se publica), `contrato_versiones` (append-only, sobre `tropilla.contrato_version/1`),
 * `contrato_animales` (compromiso «en contrato» de los individuales del lote), `tomas` + `toma_movimientos` (append-only,
 * `tropilla.toma_movimiento/1`) y `entregas` (liquidación; sobre `tropilla.entrega/1` al confirmarse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos_futuro', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('lote_id')->constrained('lotes')->restrictOnDelete();
            $table->text('codigo_publico')->unique();
            $table->text('estado')->default('borrador');
            $table->text('motivo_estado')->nullable();
            $table->integer('version_vigente')->default(0);
            $table->text('especie_codigo');
            $table->text('categoria_codigo');
            $table->integer('cabezas');
            $table->integer('kg_por_token');
            $table->integer('tokens_totales');
            $table->integer('tokens_comprometidos')->default(0);
            $table->decimal('precio_especial_kg', 14, 4);
            $table->decimal('anticipo_pct', 5, 2);
            $table->decimal('anticipo_por_token', 14, 2);
            $table->date('fecha_entrega');
            $table->integer('toma_vencimiento_horas')->default(48);
            $table->decimal('tolerancia_pct', 5, 2)->default(5);
            $table->char('moneda', 3)->default('ARS');
            $table->jsonb('terminos_borrador');
            $table->uuid('documento_id')->nullable();
            $table->timestampTz('publicado_en')->nullable();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'estado']);
        });
        DB::statement("ALTER TABLE contratos_futuro ADD CONSTRAINT contratos_estado CHECK (estado IN ('borrador','publicado','parcialmente_tomado','completo','entregado','liquidado','cancelado','incumplido'))");
        DB::statement('ALTER TABLE contratos_futuro ADD CONSTRAINT contratos_tokens CHECK (tokens_comprometidos >= 0 AND tokens_comprometidos <= tokens_totales)');
        DB::statement("ALTER TABLE contratos_futuro ADD CONSTRAINT contratos_motivo CHECK (estado NOT IN ('cancelado','incumplido') OR motivo_estado IS NOT NULL)");

        Schema::create('contrato_versiones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('contrato_id')->constrained('contratos_futuro')->restrictOnDelete();
            $table->integer('version');
            $table->text('motivo')->nullable();
            $table->jsonb('terminos');
            $table->jsonb('sobre');
            $table->char('hash', 64);
            $table->unsignedBigInteger('registro_integridad_id');
            $table->foreign('registro_integridad_id')->references('id')->on('registros_integridad')->restrictOnDelete();
            $table->foreignUuid('autor_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['contrato_id', 'version']);
        });

        Schema::create('contrato_animales', function (Blueprint $table) {
            $table->foreignUuid('contrato_id')->constrained('contratos_futuro')->restrictOnDelete();
            $table->foreignUuid('animal_id')->constrained('animales')->restrictOnDelete();
            $table->primary(['contrato_id', 'animal_id']);
            $table->index('animal_id');
        });

        Schema::create('tomas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('contrato_id')->constrained('contratos_futuro')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('comprador_id')->constrained('usuarios')->restrictOnDelete();
            $table->integer('contrato_version');
            $table->integer('tokens');
            $table->integer('tokens_vigentes');
            $table->integer('kg');
            $table->decimal('precio_especial_kg', 14, 4);
            $table->decimal('anticipo_monto', 14, 2);
            $table->decimal('anticipo_a_devolver', 14, 2)->default(0);
            $table->text('estado')->default('reservada');
            $table->timestampTz('vence_en')->nullable();
            $table->timestampsTz();
            $table->index(['contrato_id', 'estado']);
            $table->index('comprador_id');
            $table->index(['estado', 'vence_en']);
        });
        DB::statement("ALTER TABLE tomas ADD CONSTRAINT tomas_estado CHECK (estado IN ('reservada','pago_informado','confirmada','liberada','devuelta_parcial'))");

        Schema::create('toma_movimientos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('toma_id')->constrained('tomas')->restrictOnDelete();
            $table->text('tipo');
            $table->jsonb('datos');
            $table->foreignUuid('actor_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->text('rol_actor');
            $table->jsonb('sobre');
            $table->char('hash', 64);
            $table->unsignedBigInteger('registro_integridad_id');
            $table->foreign('registro_integridad_id')->references('id')->on('registros_integridad')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('toma_id');
        });

        Schema::create('entregas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('contrato_id')->unique()->constrained('contratos_futuro')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->integer('cabezas_entregadas');
            $table->decimal('peso_vivo_total_kg', 12, 1);
            $table->decimal('rendimiento_real_pct', 5, 2);
            $table->integer('kg_gancho_reales');
            $table->decimal('factor', 10, 6);
            $table->boolean('dentro_tolerancia');
            $table->jsonb('liquidacion');
            $table->text('estado')->default('cargada');
            $table->jsonb('confirmaciones')->default('[]');
            $table->uuid('documento_id')->nullable();
            $table->uuid('operacion_id')->nullable();
            $table->jsonb('sobre')->nullable();
            $table->char('hash', 64)->nullable();
            $table->unsignedBigInteger('registro_integridad_id')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE entregas ADD CONSTRAINT entregas_estado CHECK (estado IN ('cargada','confirmada_productor','confirmada','a_resolver'))");

        foreach (['contrato_versiones', 'toma_movimientos'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_inmutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()");
            DB::statement("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()");
        }
    }

    public function down(): void
    {
        foreach (['entregas', 'toma_movimientos', 'tomas', 'contrato_animales', 'contrato_versiones', 'contratos_futuro'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
