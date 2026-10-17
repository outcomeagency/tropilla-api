<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-67 — resultado de faena (romaneo) y conciliación de venta (investigacion/senasa-planillas.md §11, Res. 40/2026).
 * - `resultados_faena`: lo que informa el frigorífico de una tropa (fecha, tropa, romaneo, DT-e, cabezas, tipificador) +
 *   la liquidación tal como la emitió el frigorífico o consignataria (`liquidacion`, mutable y auditada: suele llegar después)
 *   y los documentos (romaneo, liquidación, factura). Se vincula a la venta (`operaciones`) o al egreso.
 * - `faena_reses`: una fila por res (garrón, RFID/caravana, medias reses, peso, categoría, tipificación, decomiso), **append-only**:
 *   es el dato oficial del frigorífico; se corrige anulando y volviendo a cargar.
 * La conciliación (enviados vs reses, rinde, diferencias de kg e importe) es un **cálculo de TROPILLA** sobre esto: no se guarda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados_faena', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('operacion_id')->nullable()->constrained('operaciones')->restrictOnDelete();
            $table->uuid('egreso_evento_id')->nullable();
            $table->text('frigorifico');
            $table->text('frigorifico_cuit')->nullable();
            $table->text('faenador_numero')->nullable();
            $table->date('fecha_faena');
            $table->text('tropa_numero')->nullable();
            $table->text('romaneo_numero')->nullable();
            $table->text('dte_numero')->nullable();
            $table->integer('cabezas_declaradas')->nullable();
            $table->text('tipificador')->nullable();
            $table->text('origen');
            $table->foreignUuid('documento_id')->nullable()->constrained('documentos')->restrictOnDelete();
            $table->foreignUuid('borrador_id')->nullable()->constrained('borradores_documento')->restrictOnDelete();
            $table->jsonb('liquidacion')->nullable();
            $table->foreignUuid('liquidacion_documento_id')->nullable()->constrained('documentos')->restrictOnDelete();
            $table->foreignUuid('factura_documento_id')->nullable()->constrained('documentos')->restrictOnDelete();
            $table->text('estado')->default('vigente');
            $table->text('observaciones')->nullable();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('anulado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('anulado_en')->nullable();
            $table->text('anulado_motivo')->nullable();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'fecha_faena']);
            $table->index('operacion_id');
        });
        DB::statement("ALTER TABLE resultados_faena ADD CONSTRAINT resultados_faena_origen CHECK (origen IN ('declarado','importado','transcripcion_revisada'))");
        DB::statement("ALTER TABLE resultados_faena ADD CONSTRAINT resultados_faena_original CHECK (origen = 'declarado' OR documento_id IS NOT NULL)");
        DB::statement("ALTER TABLE resultados_faena ADD CONSTRAINT resultados_faena_estado CHECK (estado IN ('vigente','anulado'))");
        DB::statement('CREATE UNIQUE INDEX resultados_faena_borrador ON resultados_faena (borrador_id) WHERE borrador_id IS NOT NULL');

        Schema::create('faena_reses', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('resultado_id')->constrained('resultados_faena')->restrictOnDelete();
            $table->smallInteger('orden');
            $table->text('garron');
            $table->text('identificacion')->nullable();
            $table->foreignUuid('animal_id')->nullable()->constrained('animales')->restrictOnDelete();
            $table->decimal('media_a_kg', 6, 1)->nullable();
            $table->decimal('media_b_kg', 6, 1)->nullable();
            $table->decimal('peso_kg', 7, 1);
            $table->text('categoria')->nullable();
            $table->text('tipificacion')->nullable();
            $table->text('decomiso')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestampTz('created_at', 3)->useCurrent();
            $table->unique(['resultado_id', 'orden']);
            $table->unique(['resultado_id', 'garron']);
            $table->index('animal_id');
        });
        DB::statement('ALTER TABLE faena_reses ADD CONSTRAINT faena_reses_peso CHECK (peso_kg > 0)');
        DB::statement('CREATE TRIGGER faena_reses_inmutable BEFORE UPDATE OR DELETE ON faena_reses FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
        DB::statement('CREATE TRIGGER faena_reses_no_truncate BEFORE TRUNCATE ON faena_reses FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()');

        $orden = (int) DB::table('tipos_documento')->max('orden');
        foreach ([['resultado_faena', 'Resultado de faena / romaneo', 'Slaughter result', 'Resultado de abate'],
            ['liquidacion_hacienda', 'Liquidación de hacienda', 'Livestock settlement', 'Liquidação de gado']] as $i => [$codigo, $es, $en, $pt]) {
            DB::table('tipos_documento')->insertOrIgnore([
                'codigo' => $codigo, 'nombres' => json_encode(['es' => $es, 'en' => $en, 'pt' => $pt], JSON_UNESCAPED_UNICODE),
                'privacidad_default' => 'comercial', 'publicable' => false, 'orden' => $orden + $i + 1,
            ]);
        }

        $this->familias(fn (array $f) => array_values(array_unique([...$f, 'resultado_faena'])));
    }

    public function down(): void
    {
        $this->familias(fn (array $f) => array_values(array_diff($f, ['resultado_faena'])));
        Schema::dropIfExists('faena_reses');
        Schema::dropIfExists('resultados_faena');
    }

    /**
     * Rehace el CHECK de familias a partir del vigente (otras migraciones suman las suyas).
     *
     * @param  callable(list<string>): list<string>  $cambio
     */
    private function familias(callable $cambio): void
    {
        $def = (string) DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'borradores_documento_familia'");
        preg_match_all("/'([a-z0-9_]+)'/", $def, $m);
        DB::statement('ALTER TABLE borradores_documento DROP CONSTRAINT IF EXISTS borradores_documento_familia');
        DB::statement('ALTER TABLE borradores_documento ADD CONSTRAINT borradores_documento_familia CHECK (familia IN ('
            .implode(',', array_map(fn ($f) => "'{$f}'", $cambio($m[1]))).'))');
    }
};
