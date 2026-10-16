<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-21 — importaciones de stock (modelo-datos.md §12) y la base de `archivos` (§10, direccionado por SHA-256)
 * que necesita la planilla. La subida diferida completa (multipart, prefirmadas) es TPA-44.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archivos', function (Blueprint $table) {
            $table->char('sha256', 64)->primary();
            $table->bigInteger('bytes')->nullable();
            $table->text('mime')->nullable();
            $table->text('s3_clave');
            $table->text('estado')->default('pendiente');
            $table->foreignUuid('subido_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('subido_en')->nullable();
            $table->timestampTz('verificado_en')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE archivos ADD CONSTRAINT archivos_estado CHECK (estado IN ('pendiente','completo','corrupto'))");
        DB::statement("ALTER TABLE archivos ADD CONSTRAINT archivos_sha256_hex CHECK (sha256 ~ '^[0-9a-f]{64}$')");

        Schema::create('importaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->char('archivo_sha256', 64);
            $table->text('formato');
            $table->text('estado')->default('subida');
            $table->text('especie_default')->nullable();
            $table->jsonb('mapeo')->default('{}');
            $table->integer('filas_total')->default(0);
            $table->integer('filas_validas')->default(0);
            $table->integer('filas_error')->default(0);
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('confirmada_en')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->foreign('archivo_sha256')->references('sha256')->on('archivos')->restrictOnDelete();
            $table->index(['establecimiento_id', 'created_at']);
        });
        DB::statement("ALTER TABLE importaciones ADD CONSTRAINT importaciones_formato CHECK (formato IN ('csv','xlsx'))");
        DB::statement("ALTER TABLE importaciones ADD CONSTRAINT importaciones_estado CHECK (estado IN ('subida','validando','validada','con_errores','confirmada','cancelada'))");

        Schema::create('importacion_filas', function (Blueprint $table) {
            $table->foreignUuid('importacion_id')->constrained('importaciones')->restrictOnDelete();
            $table->integer('numero');
            $table->jsonb('datos');
            $table->jsonb('errores')->default('[]');
            $table->jsonb('advertencias')->default('[]');
            $table->text('accion')->default('importar');
            $table->uuid('animal_id');
            $table->uuid('evento_id');
            $table->primary(['importacion_id', 'numero']);
        });
        DB::statement("ALTER TABLE importacion_filas ADD CONSTRAINT importacion_filas_accion CHECK (accion IN ('importar','descartar'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('importacion_filas');
        Schema::dropIfExists('importaciones');
        Schema::dropIfExists('archivos');
    }
};
