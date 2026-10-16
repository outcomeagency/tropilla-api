<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-61/62 — foto de planilla → borrador revisable (investigacion/senasa-planillas.md § Diseño).
 * `borradores_documento` = cabecera mutable (estado + proyección de los campos revisados) de un documento ya capturado;
 * `borrador_extracciones` y `borrador_correcciones` = evidencia **append-only**: cada extracción (proveedor, modelo,
 * esquema versionado, salida cruda) y cada decisión humana campo por campo (quién, cuándo, antes/después).
 * Nada de esto crea eventos: solo la confirmación explícita los registra por los caminos de siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borradores_documento', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('documento_id')->constrained('documentos')->restrictOnDelete();
            $table->uuid('documento_version_id');
            $table->text('familia');
            $table->text('esquema')->nullable();
            $table->text('estado');
            $table->smallInteger('intentos')->default(0);
            $table->text('error')->nullable();
            $table->jsonb('campos')->default('[]');
            $table->jsonb('advertencias')->default('[]');
            $table->integer('extraccion_vigente')->nullable();
            $table->jsonb('evento_ids')->default('[]');
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->uuid('dispositivo_id')->nullable();
            $table->foreignUuid('confirmado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('confirmado_en')->nullable();
            $table->foreignUuid('descartado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('descartado_en')->nullable();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'estado']);
            $table->index('documento_id');
        });
        DB::statement("ALTER TABLE borradores_documento ADD CONSTRAINT borradores_documento_estado CHECK (estado IN
            ('esperando_archivos','en_cola','extrayendo','para_revisar','fallido','confirmado','descartado'))");
        DB::statement("ALTER TABLE borradores_documento ADD CONSTRAINT borradores_documento_familia CHECK (familia IN ('acta_vacunacion'))");

        Schema::create('borrador_extracciones', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('borrador_id')->constrained('borradores_documento')->restrictOnDelete();
            $table->integer('version');
            $table->text('esquema');
            $table->text('proveedor');
            $table->text('modelo');
            $table->jsonb('paginas');
            $table->jsonb('salida');
            $table->jsonb('campos');
            $table->jsonb('advertencias');
            $table->integer('tokens_entrada')->nullable();
            $table->integer('tokens_salida')->nullable();
            $table->integer('duracion_ms')->nullable();
            $table->timestampTz('created_at', 3)->useCurrent();
            $table->unique(['borrador_id', 'version']);
        });

        Schema::create('borrador_correcciones', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('borrador_id')->constrained('borradores_documento')->restrictOnDelete();
            $table->integer('extraccion_version');
            $table->text('ruta');
            $table->text('estado');
            $table->jsonb('valor_anterior')->nullable();
            $table->jsonb('valor')->nullable();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('created_at', 3)->useCurrent();
            $table->index(['borrador_id', 'ruta']);
        });
        DB::statement("ALTER TABLE borrador_correcciones ADD CONSTRAINT borrador_correcciones_estado CHECK (estado IN ('correcto','editado','no_legible','no_aplica'))");

        foreach (['borrador_extracciones', 'borrador_correcciones'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_inmutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()");
            DB::statement("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('borrador_correcciones');
        Schema::dropIfExists('borrador_extracciones');
        Schema::dropIfExists('borradores_documento');
    }
};
