<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-73 — Matching de camiones jaula (piloto concierge, investigacion/negocio-datos.md §2.3 y §8).
 * `fletes_transportistas` y `fletes_transportista_documentos`: transportistas que carga el equipo de TROPILLA y su checklist
 * documental verificado a mano (quién, cuándo, cómo, vencimiento). Nada se marca «verificado» de forma automática.
 * `fletes_disponibilidades`: jaulas disponibles por zona de salida, destino, fechas y capacidad (incluye viajes de retorno).
 * `fletes_pedidos`: pedido del productor con ruta (departamento de origen, provincia/localidad de destino), ventana de fechas y carga.
 * `fletes_propuestas`: opción que el concierge le propone al productor, con los criterios de compatibilidad congelados.
 * `fletes_pedido_checklist`: diligencias del productor (entre ellas, el DT-e, que TROPILLA no emite).
 * `fletes_bitacora`: registro append-only de cada acción (quién verificó/propuso/aceptó qué y cuándo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fletes_transportistas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('nombre');
            $table->char('cuit', 11)->nullable();
            $table->text('contacto_nombre');
            $table->text('telefono');
            $table->text('email')->nullable();
            $table->text('base_departamento_codigo');
            $table->boolean('activo')->default(true);
            $table->text('notas')->nullable();
            $table->jsonb('consentimiento');
            $table->foreignUuid('cargado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('fletes_transportista_documentos', function (Blueprint $table) {
            $table->foreignUuid('transportista_id')->constrained('fletes_transportistas')->restrictOnDelete();
            $table->text('item');
            $table->text('estado');
            $table->text('metodo')->nullable();
            $table->date('vence_el')->nullable();
            $table->text('nota')->nullable();
            $table->foreignUuid('verificado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('updated_at');
            $table->primary(['transportista_id', 'item']);
        });
        DB::statement("ALTER TABLE fletes_transportista_documentos ADD CONSTRAINT fletes_documentos_estado CHECK (estado IN ('pendiente','verificado','observado','no_aplica'))");

        Schema::create('fletes_disponibilidades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transportista_id')->constrained('fletes_transportistas')->restrictOnDelete();
            $table->text('origen_departamento_codigo');
            $table->text('destino_provincia_codigo')->nullable();
            $table->date('desde');
            $table->date('hasta');
            $table->text('jaula');
            $table->integer('capacidad_cabezas');
            $table->boolean('es_retorno')->default(false);
            $table->decimal('tarifa_km', 12, 2)->nullable();
            $table->text('estado')->default('abierta');
            $table->text('notas')->nullable();
            $table->foreignUuid('cargado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['estado', 'desde', 'hasta']);
        });
        DB::statement("ALTER TABLE fletes_disponibilidades ADD COLUMN especies text[] NOT NULL DEFAULT '{BOV,BUB}'");
        DB::statement("ALTER TABLE fletes_disponibilidades ADD CONSTRAINT fletes_disp_estado CHECK (estado IN ('abierta','cerrada'))");
        DB::statement('ALTER TABLE fletes_disponibilidades ADD CONSTRAINT fletes_disp_fechas CHECK (hasta >= desde)');
        DB::statement('ALTER TABLE fletes_disponibilidades ADD CONSTRAINT fletes_disp_capacidad CHECK (capacidad_cabezas > 0)');

        Schema::create('fletes_pedidos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('origen_departamento_codigo');
            $table->text('destino_provincia_codigo');
            $table->text('destino_localidad');
            $table->text('motivo');
            $table->date('fecha_desde');
            $table->date('fecha_hasta');
            $table->text('especie_codigo');
            $table->text('categoria')->nullable();
            $table->integer('cabezas');
            $table->integer('kg_promedio')->nullable();
            $table->text('jaula_preferida')->nullable();
            $table->text('observaciones')->nullable();
            $table->text('telefono_contacto');
            $table->boolean('en_corredor');
            $table->jsonb('consentimiento');
            $table->text('estado')->default('pendiente');
            $table->text('resultado')->nullable();
            $table->decimal('resultado_costo', 14, 2)->nullable();
            $table->integer('resultado_km')->nullable();
            $table->text('resultado_nota')->nullable();
            $table->timestampTz('resultado_en')->nullable();
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'created_at']);
            $table->index(['estado', 'created_at']);
        });
        DB::statement("ALTER TABLE fletes_pedidos ADD CONSTRAINT fletes_pedidos_estado CHECK (estado IN ('pendiente','en_gestion','propuesto','contactado','acordado','sin_acuerdo','cancelado'))");
        DB::statement('ALTER TABLE fletes_pedidos ADD CONSTRAINT fletes_pedidos_fechas CHECK (fecha_hasta >= fecha_desde)');
        DB::statement('ALTER TABLE fletes_pedidos ADD CONSTRAINT fletes_pedidos_cabezas CHECK (cabezas > 0)');

        Schema::create('fletes_propuestas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pedido_id')->constrained('fletes_pedidos')->restrictOnDelete();
            $table->foreignUuid('transportista_id')->constrained('fletes_transportistas')->restrictOnDelete();
            $table->foreignUuid('disponibilidad_id')->nullable()->constrained('fletes_disponibilidades')->restrictOnDelete();
            $table->jsonb('compatibilidad');
            $table->decimal('tarifa_estimada', 14, 2)->nullable();
            $table->date('fecha_propuesta');
            $table->text('nota')->nullable();
            $table->text('estado')->default('propuesta');
            $table->text('motivo_rechazo')->nullable();
            $table->foreignUuid('propuesta_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('respondida_en')->nullable();
            $table->index('pedido_id');
        });
        DB::statement("ALTER TABLE fletes_propuestas ADD CONSTRAINT fletes_propuestas_estado CHECK (estado IN ('propuesta','aceptada','rechazada','retirada'))");

        Schema::create('fletes_pedido_checklist', function (Blueprint $table) {
            $table->foreignUuid('pedido_id')->constrained('fletes_pedidos')->restrictOnDelete();
            $table->text('item');
            $table->text('estado');
            $table->text('nota')->nullable();
            $table->foreignUuid('actualizado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('updated_at');
            $table->primary(['pedido_id', 'item']);
        });
        DB::statement("ALTER TABLE fletes_pedido_checklist ADD CONSTRAINT fletes_checklist_estado CHECK (estado IN ('pendiente','hecho','no_aplica'))");

        Schema::create('fletes_bitacora', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pedido_id')->nullable()->constrained('fletes_pedidos')->restrictOnDelete();
            $table->foreignUuid('transportista_id')->nullable()->constrained('fletes_transportistas')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('accion');
            $table->jsonb('detalle')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['pedido_id', 'created_at']);
            $table->index(['transportista_id', 'created_at']);
        });
        DB::statement('CREATE TRIGGER fletes_bitacora_inmutable BEFORE UPDATE OR DELETE ON fletes_bitacora FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('fletes_bitacora');
        Schema::dropIfExists('fletes_pedido_checklist');
        Schema::dropIfExists('fletes_propuestas');
        Schema::dropIfExists('fletes_pedidos');
        Schema::dropIfExists('fletes_disponibilidades');
        Schema::dropIfExists('fletes_transportista_documentos');
        Schema::dropIfExists('fletes_transportistas');
    }
};
