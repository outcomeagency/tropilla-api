<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-36 — modelo-datos.md §10. `documentos` = cabecera (proyección de la versión vigente + visibilidad mutable);
 * `documento_versiones` y `documento_version_archivos` = evidencia **append-only** (sobre `tropilla.documento_version/1`
 * hasheado y encadenado); `documento_vinculos` = proyección para la pestaña Documentos de animal/lote/evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->integer('version_vigente');
            $table->text('tipo_codigo');
            $table->text('privacidad');
            $table->text('titulo');
            $table->text('emisor_nombre')->nullable();
            $table->date('emitido_en')->nullable();
            $table->date('vence_en')->nullable();
            $table->text('estado')->default('vigente');
            $table->text('visibilidad')->default('privado');
            $table->timestampTz('publicado_en')->nullable();
            $table->foreignUuid('publicado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'tipo_codigo']);
        });
        DB::statement("ALTER TABLE documentos ADD CONSTRAINT documentos_privacidad CHECK (privacidad IN ('fiscal','sanitario','comercial','general'))");
        DB::statement("ALTER TABLE documentos ADD CONSTRAINT documentos_estado CHECK (estado IN ('vigente','revocado'))");
        DB::statement("ALTER TABLE documentos ADD CONSTRAINT documentos_visibilidad CHECK (visibilidad IN ('privado','perfil'))");
        DB::statement("ALTER TABLE documentos ADD CONSTRAINT documentos_fiscal_privado CHECK (NOT (privacidad = 'fiscal' AND visibilidad <> 'privado'))");
        DB::statement("CREATE INDEX documentos_vencimientos ON documentos (establecimiento_id, vence_en) WHERE estado = 'vigente'");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION tropilla_documento_publicable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.visibilidad = 'perfil' AND NOT COALESCE((SELECT publicable FROM tipos_documento WHERE codigo = NEW.tipo_codigo), false) THEN
                    RAISE EXCEPTION 'tipo de documento no publicable: %', NEW.tipo_codigo USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER documentos_publicable BEFORE INSERT OR UPDATE ON documentos FOR EACH ROW EXECUTE FUNCTION tropilla_documento_publicable()');

        Schema::create('documento_versiones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('documento_id')->constrained('documentos')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->integer('version');
            $table->text('accion');
            $table->text('motivo')->nullable();
            $table->text('tipo_codigo');
            $table->text('privacidad');
            $table->text('titulo');
            $table->text('emisor_nombre')->nullable();
            $table->text('emisor_identificacion')->nullable();
            $table->date('emitido_en')->nullable();
            $table->date('vence_en')->nullable();
            $table->jsonb('vinculos')->default('[]');
            $table->foreignUuid('autor_id')->constrained('usuarios')->restrictOnDelete();
            $table->uuid('dispositivo_id')->nullable();
            $table->timestampTz('creado_en', 3);
            $table->timestampTz('registrado_en', 3)->useCurrent();
            $table->boolean('en_revision')->default(false);
            $table->jsonb('sobre');
            $table->char('hash', 64);
            $table->char('hash_anterior', 64)->nullable();
            $table->unsignedBigInteger('registro_integridad_id');
            $table->foreign('registro_integridad_id')->references('id')->on('registros_integridad')->restrictOnDelete();
            $table->index('documento_id');
        });
        DB::statement("ALTER TABLE documento_versiones ADD CONSTRAINT documento_versiones_accion CHECK (accion IN ('alta','nueva_version','correccion_metadatos','revocacion'))");
        DB::statement('CREATE UNIQUE INDEX documento_versiones_unica ON documento_versiones (documento_id, version) WHERE NOT en_revision');

        Schema::create('documento_version_archivos', function (Blueprint $table) {
            $table->foreignUuid('documento_version_id')->constrained('documento_versiones')->restrictOnDelete();
            $table->smallInteger('orden');
            $table->char('archivo_sha256', 64);
            $table->foreign('archivo_sha256')->references('sha256')->on('archivos')->restrictOnDelete();
            $table->primary(['documento_version_id', 'orden']);
            $table->index('archivo_sha256');
        });

        foreach (['documento_versiones', 'documento_version_archivos'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_inmutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()");
            DB::statement("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()");
        }

        Schema::create('documento_vinculos', function (Blueprint $table) {
            $table->foreignUuid('documento_id')->constrained('documentos')->restrictOnDelete();
            $table->text('entidad_tipo');
            $table->uuid('entidad_id');
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->primary(['documento_id', 'entidad_tipo', 'entidad_id']);
            $table->index(['entidad_tipo', 'entidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_vinculos');
        Schema::dropIfExists('documento_version_archivos');
        Schema::dropIfExists('documento_versiones');
        Schema::dropIfExists('documentos');
        DB::statement('DROP FUNCTION IF EXISTS tropilla_documento_publicable() CASCADE');
    }
};
