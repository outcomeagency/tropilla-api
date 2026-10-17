<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-66 — pruebas, certificados y estatus de brucelosis/TBC (investigacion/senasa-planillas.md §5–6).
 * - `protocolos_sanitarios`: muestreo/prueba (DOES, recertificación, saneamiento, precondición de movimiento…) con
 *   laboratorio, protocolo, veterinario y el documento original; inmutable una vez registrado (se anula, no se edita).
 * - `protocolo_resultados`: resultado por animal tal como figura (identificación transcripta + animal de TROPILLA si
 *   se pudo asociar); **append-only**. Los resultados asociados generan `sanidad.prueba_diagnostica` (reactor, timeline).
 * - `estatus_sanitarios`: historial de la condición del establecimiento por enfermedad (certificado, vigencia). Es una
 *   **transcripción** o una declaración: la condición oficial la certifica SENASA (SIGSA), nunca TROPILLA.
 * - `planes_saneamiento`: plan con acciones, responsables y próximo muestreo, enlazado al original.
 * Aditiva: amplía `tipos_prueba` (enfermedad) y el CHECK de familias de borradores sin pisar familias de otros tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_prueba', function (Blueprint $table) {
            $table->text('enfermedad')->nullable();
        });

        Schema::create('protocolos_sanitarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('enfermedad');
            $table->text('prueba_codigo');
            $table->text('motivo');
            $table->text('modalidad')->nullable();
            $table->integer('stock_base')->nullable();
            $table->date('fecha_muestreo');
            $table->date('fecha_resultado')->nullable();
            $table->text('laboratorio')->nullable();
            $table->text('protocolo_numero')->nullable();
            $table->text('veterinario_nombre')->nullable();
            $table->text('veterinario_matricula')->nullable();
            $table->date('vence_el')->nullable();
            $table->text('origen');
            $table->foreignUuid('documento_id')->nullable()->constrained('documentos')->restrictOnDelete();
            $table->foreignUuid('borrador_id')->nullable()->constrained('borradores_documento')->restrictOnDelete();
            $table->text('estado')->default('vigente');
            $table->text('observaciones')->nullable();
            $table->jsonb('evento_ids')->default('[]');
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('anulado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('anulado_en')->nullable();
            $table->text('anulado_motivo')->nullable();
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'enfermedad', 'fecha_muestreo']);
        });
        DB::statement("ALTER TABLE protocolos_sanitarios ADD CONSTRAINT protocolos_sanitarios_enfermedad CHECK (enfermedad IN ('brucelosis','tuberculosis'))");
        DB::statement("ALTER TABLE protocolos_sanitarios ADD CONSTRAINT protocolos_sanitarios_motivo CHECK (motivo IN ('does','recertificacion','saneamiento','movimiento','rutina','otro'))");
        DB::statement("ALTER TABLE protocolos_sanitarios ADD CONSTRAINT protocolos_sanitarios_modalidad CHECK (modalidad IS NULL OR modalidad IN ('total','muestreo'))");
        DB::statement("ALTER TABLE protocolos_sanitarios ADD CONSTRAINT protocolos_sanitarios_origen CHECK (origen IN ('declarado','transcripcion_revisada'))");
        DB::statement('ALTER TABLE protocolos_sanitarios ADD CONSTRAINT protocolos_sanitarios_original CHECK (origen <> \'transcripcion_revisada\' OR documento_id IS NOT NULL)');
        DB::statement("ALTER TABLE protocolos_sanitarios ADD CONSTRAINT protocolos_sanitarios_estado CHECK (estado IN ('vigente','anulado'))");
        DB::statement('CREATE UNIQUE INDEX protocolos_sanitarios_borrador ON protocolos_sanitarios (borrador_id) WHERE borrador_id IS NOT NULL');

        Schema::create('protocolo_resultados', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('protocolo_id')->constrained('protocolos_sanitarios')->restrictOnDelete();
            $table->smallInteger('orden');
            $table->foreignUuid('animal_id')->nullable()->constrained('animales')->restrictOnDelete();
            $table->text('identificacion')->nullable();
            $table->text('categoria_codigo')->nullable();
            $table->text('muestra')->nullable();
            $table->text('resultado');
            $table->decimal('lectura_0h_mm', 5, 1)->nullable();
            $table->decimal('lectura_72h_mm', 5, 1)->nullable();
            $table->text('observaciones')->nullable();
            $table->uuid('evento_id')->nullable();
            $table->timestampTz('created_at', 3)->useCurrent();
            $table->unique(['protocolo_id', 'orden']);
            $table->index('animal_id');
        });
        DB::statement("ALTER TABLE protocolo_resultados ADD CONSTRAINT protocolo_resultados_resultado CHECK (resultado IN ('negativo','sospechoso','positivo','no_concluyente','no_apto'))");
        DB::statement('ALTER TABLE protocolo_resultados ADD CONSTRAINT protocolo_resultados_id CHECK (animal_id IS NOT NULL OR identificacion IS NOT NULL)');
        DB::statement('CREATE TRIGGER protocolo_resultados_inmutable BEFORE UPDATE OR DELETE ON protocolo_resultados FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
        DB::statement('CREATE TRIGGER protocolo_resultados_no_truncate BEFORE TRUNCATE ON protocolo_resultados FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()');

        Schema::create('estatus_sanitarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('enfermedad');
            $table->text('estatus');
            $table->text('origen');
            $table->text('certificado_numero')->nullable();
            $table->text('autoridad')->nullable();
            $table->date('emitido_el')->nullable();
            $table->date('vigente_desde');
            $table->date('vence_el')->nullable();
            $table->foreignUuid('protocolo_id')->nullable()->constrained('protocolos_sanitarios')->restrictOnDelete();
            $table->foreignUuid('documento_id')->nullable()->constrained('documentos')->restrictOnDelete();
            $table->text('observaciones')->nullable();
            $table->text('estado')->default('vigente');
            $table->uuid('reemplazado_por')->nullable();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('anulado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('anulado_en')->nullable();
            $table->text('anulado_motivo')->nullable();
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'enfermedad', 'estado']);
        });
        DB::statement("ALTER TABLE estatus_sanitarios ADD CONSTRAINT estatus_sanitarios_enfermedad CHECK (enfermedad IN ('brucelosis','tuberculosis'))");
        DB::statement("ALTER TABLE estatus_sanitarios ADD CONSTRAINT estatus_sanitarios_estatus CHECK (estatus IN ('libre','en_saneamiento','en_certificacion','sin_estatus','suspendido'))");
        DB::statement("ALTER TABLE estatus_sanitarios ADD CONSTRAINT estatus_sanitarios_origen CHECK (origen IN ('declarado','transcripcion_revisada'))");
        DB::statement('ALTER TABLE estatus_sanitarios ADD CONSTRAINT estatus_sanitarios_original CHECK (origen <> \'transcripcion_revisada\' OR documento_id IS NOT NULL)');
        DB::statement("ALTER TABLE estatus_sanitarios ADD CONSTRAINT estatus_sanitarios_estado CHECK (estado IN ('vigente','reemplazado','anulado'))");
        DB::statement('ALTER TABLE estatus_sanitarios ADD CONSTRAINT estatus_sanitarios_vigencia CHECK (vence_el IS NULL OR vence_el >= vigente_desde)');
        DB::statement("CREATE UNIQUE INDEX estatus_sanitarios_vigente ON estatus_sanitarios (establecimiento_id, enfermedad) WHERE estado = 'vigente'");

        Schema::create('planes_saneamiento', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('enfermedad');
            $table->text('estado')->default('activo');
            $table->date('inicio');
            $table->date('proximo_muestreo')->nullable();
            $table->text('veterinario_nombre')->nullable();
            $table->text('veterinario_matricula')->nullable();
            $table->foreignUuid('protocolo_id')->nullable()->constrained('protocolos_sanitarios')->restrictOnDelete();
            $table->foreignUuid('documento_id')->nullable()->constrained('documentos')->restrictOnDelete();
            $table->jsonb('acciones')->default('[]');
            $table->text('observaciones')->nullable();
            $table->date('cerrado_el')->nullable();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'enfermedad', 'estado']);
        });
        DB::statement("ALTER TABLE planes_saneamiento ADD CONSTRAINT planes_saneamiento_enfermedad CHECK (enfermedad IN ('brucelosis','tuberculosis'))");
        DB::statement("ALTER TABLE planes_saneamiento ADD CONSTRAINT planes_saneamiento_estado CHECK (estado IN ('activo','cumplido','anulado'))");
        DB::statement("CREATE UNIQUE INDEX planes_saneamiento_activo ON planes_saneamiento (establecimiento_id, enfermedad) WHERE estado = 'activo'");

        $this->familias(fn (array $f) => array_values(array_unique([...$f, 'protocolo_sanitario'])));
    }

    public function down(): void
    {
        $this->familias(fn (array $f) => array_values(array_diff($f, ['protocolo_sanitario'])));
        Schema::dropIfExists('planes_saneamiento');
        Schema::dropIfExists('estatus_sanitarios');
        Schema::dropIfExists('protocolo_resultados');
        Schema::dropIfExists('protocolos_sanitarios');
        Schema::table('tipos_prueba', fn (Blueprint $table) => $table->dropColumn('enfermedad'));
    }

    /**
     * Rehace el CHECK de familias a partir del vigente (otras migraciones pueden haber sumado las suyas).
     *
     * @param  callable(list<string>): list<string>  $cambio
     */
    private function familias(callable $cambio): void
    {
        $def = (string) DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'borradores_documento_familia'");
        preg_match_all("/'([a-z0-9_]+)'/", $def, $m);
        $lista = $cambio($m[1]);
        DB::statement('ALTER TABLE borradores_documento DROP CONSTRAINT IF EXISTS borradores_documento_familia');
        DB::statement('ALTER TABLE borradores_documento ADD CONSTRAINT borradores_documento_familia CHECK (familia IN ('
            .implode(',', array_map(fn ($f) => "'{$f}'", $lista)).'))');
    }
};
