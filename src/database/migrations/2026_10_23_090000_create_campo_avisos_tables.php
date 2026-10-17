<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-74 — Mercado de campos y pastajes (piloto regional).
 * `campo_avisos`: campo ofrecido por un establecimiento (pastaje, arrendamiento o capitalización) con especie, capacidad declarada y
 * temporada. Ubicación solo por provincia/departamento (nunca coordenadas ni polígono). `verificacion` dice el nivel real de
 * comprobación que hizo TROPILLA (por defecto, «declarado» por el anunciante); no es inspección ni garantía del inmueble.
 * `campo_consultas`: pedido de un productor interesado, con su rodeo y el consentimiento para compartir el contacto.
 * `campo_disputas`: reporte sobre un aviso u operación, con descargo del anunciante y resolución del equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campo_avisos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('autor_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('titulo');
            $table->text('descripcion')->nullable();
            $table->text('modalidad');
            $table->integer('capacidad_cabezas');
            $table->decimal('superficie_ha', 12, 2)->nullable();
            $table->date('temporada_desde');
            $table->date('temporada_hasta')->nullable();
            $table->smallInteger('plazo_minimo_meses')->nullable();
            $table->text('precio_unidad');
            $table->decimal('precio_valor', 14, 2)->nullable();
            $table->text('condiciones')->nullable();
            $table->text('alambrado')->nullable();
            $table->text('accesos')->nullable();
            $table->text('provincia_codigo');
            $table->text('departamento_codigo');
            $table->text('localidad')->nullable();
            $table->boolean('mostrar_localidad')->default(false);
            $table->text('estado')->default('borrador');
            $table->text('verificacion')->default('declarado');
            $table->text('verificacion_nota')->nullable();
            $table->foreignUuid('verificado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('verificado_en')->nullable();
            $table->timestampTz('publicado_en')->nullable();
            $table->timestampTz('cerrado_en')->nullable();
            $table->text('motivo_cierre')->nullable();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'estado']);
            $table->index(['estado', 'provincia_codigo']);
        });
        DB::statement("ALTER TABLE campo_avisos ADD COLUMN especies text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE campo_avisos ADD COLUMN categorias text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE campo_avisos ADD COLUMN agua text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE campo_avisos ADD COLUMN instalaciones text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE campo_avisos ADD CONSTRAINT campo_avisos_modalidad CHECK (modalidad IN ('pastaje','arrendamiento','capitalizacion'))");
        DB::statement("ALTER TABLE campo_avisos ADD CONSTRAINT campo_avisos_estado CHECK (estado IN ('borrador','publicado','pausado','en_revision','cerrado'))");
        DB::statement("ALTER TABLE campo_avisos ADD CONSTRAINT campo_avisos_verificacion CHECK (verificacion IN ('declarado','contacto_verificado','documentacion_revisada'))");
        DB::statement("ALTER TABLE campo_avisos ADD CONSTRAINT campo_avisos_precio CHECK (precio_unidad IN ('por_cabeza_mes','por_ha_anio','kg_carne_ha_anio','porcentaje_ganancia','a_convenir'))");
        DB::statement("ALTER TABLE campo_avisos ADD CONSTRAINT campo_avisos_alambrado CHECK (alambrado IS NULL OR alambrado IN ('bueno','regular','a_reparar'))");
        DB::statement('ALTER TABLE campo_avisos ADD CONSTRAINT campo_avisos_capacidad CHECK (capacidad_cabezas > 0)');
        DB::statement('ALTER TABLE campo_avisos ADD CONSTRAINT campo_avisos_especies CHECK (cardinality(especies) > 0)');
        DB::statement('ALTER TABLE campo_avisos ADD CONSTRAINT campo_avisos_temporada CHECK (temporada_hasta IS NULL OR temporada_hasta >= temporada_desde)');

        Schema::create('campo_consultas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('aviso_id')->constrained('campo_avisos')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('mensaje');
            $table->text('especie_codigo');
            $table->integer('cabezas');
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();
            $table->jsonb('contacto_interesado');
            $table->jsonb('consentimiento');
            $table->text('estado')->default('enviada');
            $table->text('respuesta')->nullable();
            $table->jsonb('contacto_anunciante')->nullable();
            $table->text('nota_cierre')->nullable();
            $table->timestampTz('respondida_en')->nullable();
            $table->timestampTz('cerrada_en')->nullable();
            $table->timestampsTz();
            $table->index(['aviso_id', 'created_at']);
            $table->index(['establecimiento_id', 'created_at']);
        });
        DB::statement("ALTER TABLE campo_consultas ADD COLUMN categorias text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE campo_consultas ADD CONSTRAINT campo_consultas_estado CHECK (estado IN ('enviada','respondida','visita_coordinada','acuerdo','sin_acuerdo','descartada','retirada'))");
        DB::statement('ALTER TABLE campo_consultas ADD CONSTRAINT campo_consultas_cabezas CHECK (cabezas > 0)');
        DB::statement("CREATE UNIQUE INDEX campo_consultas_abierta ON campo_consultas (aviso_id, establecimiento_id) WHERE estado IN ('enviada','respondida','visita_coordinada')");

        Schema::create('campo_disputas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('aviso_id')->constrained('campo_avisos')->restrictOnDelete();
            $table->foreignUuid('consulta_id')->nullable()->constrained('campo_consultas')->restrictOnDelete();
            $table->foreignUuid('denunciante_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('motivo');
            $table->text('detalle');
            $table->text('estado')->default('abierta');
            $table->text('descargo')->nullable();
            $table->timestampTz('descargo_en')->nullable();
            $table->text('resolucion')->nullable();
            $table->text('accion')->nullable();
            $table->foreignUuid('resuelta_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('resuelta_en')->nullable();
            $table->timestampsTz();
            $table->index(['estado', 'created_at']);
            $table->index('aviso_id');
        });
        DB::statement("ALTER TABLE campo_disputas ADD CONSTRAINT campo_disputas_motivo CHECK (motivo IN ('aviso_inexistente','datos_falsos','incumplimiento','conducta','otro'))");
        DB::statement("ALTER TABLE campo_disputas ADD CONSTRAINT campo_disputas_estado CHECK (estado IN ('abierta','en_revision','resuelta','desestimada'))");
        DB::statement("ALTER TABLE campo_disputas ADD CONSTRAINT campo_disputas_accion CHECK (accion IS NULL OR accion IN ('ninguna','aviso_pausado','aviso_cerrado','verificacion_retirada'))");
        DB::statement("CREATE UNIQUE INDEX campo_disputas_abierta ON campo_disputas (aviso_id, denunciante_id) WHERE estado IN ('abierta','en_revision')");
    }

    public function down(): void
    {
        Schema::dropIfExists('campo_disputas');
        Schema::dropIfExists('campo_consultas');
        Schema::dropIfExists('campo_avisos');
    }
};
