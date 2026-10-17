<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-75 — tablero agregado con gobernanza (investigación negocio-datos §1, §5, §7). Prototipo **solo sintético**:
 * licencias de clientes institucionales por métrica, política de divulgación versionada (k mínimo, dominancia, redondeo,
 * cupo), consultas auditadas, pruebas de reidentificación y revisiones del gate previo a cualquier opt-in real.
 * Política, consultas, pruebas, revisiones y bitácora son append-only.
 */
return new class extends Migration
{
    private const APPEND_ONLY = ['agregados_politicas', 'agregados_consultas', 'agregados_pruebas', 'agregados_revisiones', 'agregados_bitacora'];

    public function up(): void
    {
        Schema::create('agregados_sinteticos', function (Blueprint $table) {
            $table->id();
            $table->text('dataset_version');
            $table->text('establecimiento_ref');
            $table->text('provincia');
            $table->text('especie_codigo');
            $table->text('escala');
            $table->char('trimestre', 6);
            $table->integer('cabezas');
            $table->integer('vacunados');
            $table->integer('kg_vendidos');
            $table->bigInteger('importe_ventas');
            $table->index(['dataset_version', 'especie_codigo', 'trimestre']);
            $table->unique(['dataset_version', 'establecimiento_ref', 'trimestre']);
        });
        DB::statement("ALTER TABLE agregados_sinteticos ADD CONSTRAINT agregados_sinteticos_escala CHECK (escala IN ('chico','mediano','grande'))");

        Schema::create('agregados_politicas', function (Blueprint $table) {
            $table->id();
            $table->integer('version')->unique();
            $table->integer('k_minimo');
            $table->integer('dominancia_max_pct');
            $table->integer('redondeo_cabezas');
            $table->integer('consultas_por_dia');
            $table->boolean('desglose_escala');
            $table->text('nota')->nullable();
            $table->foreignUuid('creado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE agregados_politicas ADD CONSTRAINT agregados_politicas_rangos CHECK (k_minimo BETWEEN 5 AND 100 AND dominancia_max_pct BETWEEN 30 AND 90 AND redondeo_cabezas IN (10,50,100,500) AND consultas_por_dia BETWEEN 1 AND 500)');

        Schema::create('agregados_clientes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('nombre');
            $table->text('tipo');
            $table->text('finalidad');
            $table->text('contrato_version');
            $table->date('vigente_hasta')->nullable();
            $table->text('estado')->default('activo');
            $table->integer('consultas_por_dia')->nullable();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE agregados_clientes ADD COLUMN metricas text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE agregados_clientes ADD CONSTRAINT agregados_clientes_tipo CHECK (tipo IN ('frigorifico','consignataria','banco','aseguradora','gobierno','proveedor','asociacion','otro'))");
        DB::statement("ALTER TABLE agregados_clientes ADD CONSTRAINT agregados_clientes_estado CHECK (estado IN ('activo','suspendido'))");

        Schema::create('agregados_cliente_miembros', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cliente_id')->constrained('agregados_clientes')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('estado')->default('activa');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE agregados_cliente_miembros ADD CONSTRAINT agregados_cliente_miembros_estado CHECK (estado IN ('activa','revocada'))");
        DB::statement("CREATE UNIQUE INDEX agregados_cliente_miembros_activo ON agregados_cliente_miembros (cliente_id, usuario_id) WHERE estado = 'activa'");

        Schema::create('agregados_consultas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cliente_id')->nullable()->constrained('agregados_clientes')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('tipo');
            $table->jsonb('parametros');
            $table->integer('celdas');
            $table->integer('suprimidas');
            $table->integer('politica_version');
            $table->text('dataset_version');
            $table->text('fuente');
            $table->char('ip_hash', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['cliente_id', 'created_at']);
        });
        DB::statement("ALTER TABLE agregados_consultas ADD CONSTRAINT agregados_consultas_tipo CHECK (tipo IN ('tablero','exportacion'))");
        DB::statement("ALTER TABLE agregados_consultas ADD CONSTRAINT agregados_consultas_fuente CHECK (fuente IN ('sintetico'))");

        Schema::create('agregados_pruebas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('politica_version');
            $table->text('dataset_version');
            $table->text('resultado');
            $table->jsonb('controles');
            $table->foreignUuid('ejecutada_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE agregados_pruebas ADD CONSTRAINT agregados_pruebas_resultado CHECK (resultado IN ('aprobada','fallida'))");

        Schema::create('agregados_revisiones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('aspecto');
            $table->text('resultado');
            $table->text('nota');
            $table->integer('politica_version');
            $table->foreignUuid('prueba_id')->nullable()->constrained('agregados_pruebas')->restrictOnDelete();
            $table->foreignUuid('revisor_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE agregados_revisiones ADD CONSTRAINT agregados_revisiones_aspecto CHECK (aspecto IN ('reidentificacion','permisos','cohortes','auditoria'))");
        DB::statement("ALTER TABLE agregados_revisiones ADD CONSTRAINT agregados_revisiones_resultado CHECK (resultado IN ('aprobado','observado'))");

        Schema::create('agregados_bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('cliente_id')->nullable()->constrained('agregados_clientes')->restrictOnDelete();
            $table->text('accion');
            $table->jsonb('detalle')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
        });

        foreach (self::APPEND_ONLY as $t) {
            DB::statement("CREATE TRIGGER {$t}_inmutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()");
            DB::statement("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()");
        }

        // Política inicial: celdas de al menos 10 establecimientos, ningún aportante con más del 50 %, cabezas redondeadas a 100.
        DB::table('agregados_politicas')->insert(['version' => 1, 'k_minimo' => 10, 'dominancia_max_pct' => 50, 'redondeo_cabezas' => 100,
            'consultas_por_dia' => 40, 'desglose_escala' => true, 'nota' => 'Política inicial del prototipo sintético (TPA-75).']);
    }

    public function down(): void
    {
        foreach (['agregados_bitacora', 'agregados_revisiones', 'agregados_pruebas', 'agregados_consultas', 'agregados_cliente_miembros', 'agregados_clientes', 'agregados_politicas', 'agregados_sinteticos'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
