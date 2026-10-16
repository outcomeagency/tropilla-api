<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-10 — Tenencia (modelo-datos.md §2.3–2.5) + piezas transversales que nacen con el
 * establecimiento: `cadenas` y `cambios` (§8, cursor de sync) y `auditoria` (§12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_departamentos', function (Blueprint $table) {
            $table->text('codigo')->primary(); // INDEC 5 dígitos
            $table->text('provincia_codigo');
            $table->text('provincia_nombre');
            $table->text('nombre');
            $table->text('garrapata_sugerida')->nullable();
            $table->index('provincia_codigo');
        });
        DB::statement('ALTER TABLE geo_departamentos ADD COLUMN centroide geography(Point,4326) NULL');
        DB::statement("ALTER TABLE geo_departamentos ADD CONSTRAINT geo_departamentos_garrapata CHECK (garrapata_sugerida IN ('erradicacion','control','libre'))");

        Schema::create('productores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('tipo');
            $table->text('nombre');
            $table->text('cuit_cifrado')->nullable();
            $table->char('cuit_hash', 64)->nullable()->index();
            $table->jsonb('contacto')->default('{}');
            $table->jsonb('asociaciones')->default('[]');
            $table->char('logo_sha256', 64)->nullable();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE productores ADD CONSTRAINT productores_tipo CHECK (tipo IN ('persona_humana','sociedad'))");

        Schema::create('establecimientos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('productor_id')->constrained('productores')->restrictOnDelete();
            $table->text('nombre');
            $table->text('provincia_codigo');
            $table->text('departamento_codigo');
            $table->text('localidad')->nullable();
            $table->text('renspa')->nullable();
            $table->decimal('superficie_ha', 12, 2)->nullable();
            $table->jsonb('zonas_sanitarias')->default('{}');
            $table->text('historia')->nullable();
            $table->text('zona_horaria')->default('America/Argentina/Buenos_Aires');
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->timestampTz('archivado_en')->nullable();
            $table->index('productor_id');
        });
        DB::statement('ALTER TABLE establecimientos ADD COLUMN ubicacion geography(Point,4326) NULL');
        DB::statement("ALTER TABLE establecimientos ADD COLUMN actividades text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE establecimientos ADD COLUMN especies text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE establecimientos ADD COLUMN instalaciones text[] NOT NULL DEFAULT '{}'");
        DB::statement('ALTER TABLE establecimientos ADD CONSTRAINT establecimientos_actividades CHECK (cardinality(actividades) > 0)');
        DB::statement('ALTER TABLE establecimientos ADD CONSTRAINT establecimientos_especies CHECK (cardinality(especies) > 0)');
        DB::statement('CREATE INDEX establecimientos_ubicacion ON establecimientos USING gist (ubicacion)');

        Schema::create('membresias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('rol');
            $table->text('estado')->default('activa');
            $table->foreignUuid('invitado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('aceptada_en');
            $table->timestampTz('revocada_en')->nullable();
            $table->foreignUuid('revocada_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->text('motivo_revocacion')->nullable();
            $table->boolean('es_profesional_firmante')->default(false);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE membresias ADD CONSTRAINT membresias_rol CHECK (rol IN ('dueno','admin','encargado','asesor','lectura'))");
        DB::statement("ALTER TABLE membresias ADD CONSTRAINT membresias_estado CHECK (estado IN ('activa','revocada'))");
        DB::statement("CREATE UNIQUE INDEX membresias_una_activa ON membresias (establecimiento_id, usuario_id) WHERE estado = 'activa'");
        DB::statement("CREATE UNIQUE INDEX membresias_un_dueno ON membresias (establecimiento_id) WHERE rol = 'dueno' AND estado = 'activa'");
        DB::statement("CREATE INDEX membresias_usuario_activa ON membresias (usuario_id) WHERE estado = 'activa'");

        // §8: la fila que se bloquea (FOR UPDATE) para encadenar y para asignar cambios.seq.
        Schema::create('cadenas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->nullable()->unique()->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('productor_id')->nullable()->unique()->constrained('productores')->restrictOnDelete();
            $table->bigInteger('ultimo_seq')->default(0);
            $table->char('ultimo_eslabon', 64)->default(str_repeat('0', 64));
            $table->bigInteger('ultimo_seq_cambios')->default(0);
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE cadenas ADD CONSTRAINT cadenas_un_duenio CHECK (num_nonnulls(establecimiento_id, productor_id) = 1)');

        // §8: cursor de sync por establecimiento (append-only; compactable por migración controlada).
        Schema::create('cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->bigInteger('seq');
            $table->text('entidad');
            $table->uuid('entidad_id');
            $table->text('op')->default('upsert');
            $table->text('visibilidad')->default('general');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['establecimiento_id', 'seq']);
        });
        DB::statement("ALTER TABLE cambios ADD CONSTRAINT cambios_entidad CHECK (entidad IN ('evento','animal','lote','potrero','documento','producto_sanitario','tarea','revision','membresia','establecimiento','anclaje','plan_item'))");
        DB::statement("ALTER TABLE cambios ADD CONSTRAINT cambios_op CHECK (op IN ('upsert','archivar','ocultar'))");
        DB::statement("ALTER TABLE cambios ADD CONSTRAINT cambios_visibilidad CHECK (visibilidad IN ('general','comercial','fiscal'))");

        // §12: bitácora de todo lo mutable (append-only).
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('establecimiento_id')->nullable()->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->text('entidad');
            $table->uuid('entidad_id')->nullable();
            $table->text('accion');
            $table->jsonb('antes')->nullable();
            $table->jsonb('despues')->nullable();
            $table->text('motivo')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->uuid('dispositivo_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['entidad', 'entidad_id']);
        });
        DB::statement('CREATE INDEX auditoria_est_fecha ON auditoria (establecimiento_id, created_at DESC)');

        foreach (['cambios', 'auditoria'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_inmutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()");
            DB::statement("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()");
        }
    }

    public function down(): void
    {
        foreach (['auditoria', 'cambios', 'cadenas', 'membresias', 'establecimientos', 'productores', 'geo_departamentos'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
