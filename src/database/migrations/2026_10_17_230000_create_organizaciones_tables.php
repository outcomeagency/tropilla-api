<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-51 — modelo-datos §2.7: organizaciones (alta controlada por el equipo en MVP), sus miembros (admin/operador), adhesiones del
 * productor (proyección) y consentimientos **append-only** encadenados en la cadena del productor (`tropilla.consentimiento/1`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('nombre');
            $table->text('slug')->unique();
            $table->text('tipo');
            $table->jsonb('ambito')->default('{}');
            $table->char('logo_sha256', 64)->nullable();
            $table->text('descripcion')->nullable();
            $table->timestampTz('verificada_por_tropilla_en')->nullable();
            $table->timestampsTz();
            $table->timestampTz('archivado_en')->nullable();
        });
        DB::statement("ALTER TABLE organizaciones ADD COLUMN especies text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE organizaciones ADD CONSTRAINT organizaciones_tipo CHECK (tipo IN ('asociacion','programa_provincial','cooperativa','consejo_regulador','otra'))");

        Schema::create('organizacion_miembros', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organizacion_id')->constrained('organizaciones')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('rol');
            $table->text('estado')->default('activa');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE organizacion_miembros ADD CONSTRAINT organizacion_miembros_rol CHECK (rol IN ('admin','operador'))");
        DB::statement("CREATE UNIQUE INDEX organizacion_miembros_activo ON organizacion_miembros (organizacion_id, usuario_id) WHERE estado = 'activa'");

        Schema::create('adhesiones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('productor_id')->constrained('productores')->restrictOnDelete();
            $table->foreignUuid('organizacion_id')->constrained('organizaciones')->restrictOnDelete();
            $table->text('estado')->default('activa');
            $table->text('numero_socio')->nullable();
            $table->integer('version_vigente')->default(1);
            $table->timestampsTz();
            $table->unique(['productor_id', 'organizacion_id']);
        });
        DB::statement("ALTER TABLE adhesiones ADD CONSTRAINT adhesiones_estado CHECK (estado IN ('activa','revocada'))");

        Schema::create('consentimientos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('adhesion_id')->constrained('adhesiones')->restrictOnDelete();
            $table->integer('version');
            $table->text('accion');
            $table->jsonb('alcances');
            $table->text('texto_version');
            $table->text('motivo')->nullable();
            $table->foreignUuid('otorgado_por')->constrained('usuarios')->restrictOnDelete();
            $table->jsonb('sobre');
            $table->char('hash', 64);
            $table->unsignedBigInteger('registro_integridad_id');
            $table->foreign('registro_integridad_id')->references('id')->on('registros_integridad')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['adhesion_id', 'version']);
        });
        DB::statement("ALTER TABLE consentimientos ADD CONSTRAINT consentimientos_accion CHECK (accion IN ('otorga','modifica','revoca'))");
        DB::statement('CREATE TRIGGER consentimientos_inmutable BEFORE UPDATE OR DELETE ON consentimientos FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
        DB::statement('CREATE TRIGGER consentimientos_no_truncate BEFORE TRUNCATE ON consentimientos FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()');
    }

    public function down(): void
    {
        foreach (['consentimientos', 'adhesiones', 'organizacion_miembros', 'organizaciones'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
