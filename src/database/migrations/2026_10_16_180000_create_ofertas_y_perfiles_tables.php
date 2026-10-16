<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-48 — ofertas (modelo-datos §9.4) y la base del perfil público (§11), que la ficha pública de la oferta
 * necesita (slug del establecimiento). Publicaciones de oferta y de perfil: append-only, hasheadas y encadenadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ofertas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('codigo_publico')->unique();
            $table->text('tipo');
            $table->text('especie_codigo');
            $table->foreignUuid('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();
            $table->integer('cantidad');
            $table->text('estado')->default('borrador');
            $table->jsonb('mostrar')->default('{}');
            $table->jsonb('condiciones')->default('{}');
            $table->jsonb('textos')->default('{}');
            $table->integer('publicacion_vigente')->nullable();
            $table->jsonb('avisos')->default('[]');
            $table->timestampTz('publicada_en')->nullable();
            $table->timestampTz('retirada_en')->nullable();
            $table->text('motivo_retiro')->nullable();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->foreign('especie_codigo')->references('codigo')->on('especies')->restrictOnDelete();
            $table->index(['establecimiento_id', 'estado']);
        });
        DB::statement("ALTER TABLE ofertas ADD COLUMN categorias text[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE ofertas ADD COLUMN razas text[] NOT NULL DEFAULT '{}'");
        DB::statement('ALTER TABLE ofertas ADD COLUMN fotos char(64)[] NULL');
        DB::statement("ALTER TABLE ofertas ADD CONSTRAINT ofertas_estado CHECK (estado IN ('borrador','publicada','reservada','vendida','retirada'))");
        DB::statement("ALTER TABLE ofertas ADD CONSTRAINT ofertas_tipo CHECK (tipo IN ('invernada','gordo','cria','reproductores'))");
        DB::statement('ALTER TABLE ofertas ADD CONSTRAINT ofertas_cantidad CHECK (cantidad > 0)');

        Schema::create('oferta_animales', function (Blueprint $table) {
            $table->foreignUuid('oferta_id')->constrained('ofertas')->restrictOnDelete();
            $table->foreignUuid('animal_id')->constrained('animales')->restrictOnDelete();
            $table->uuid('establecimiento_id');
            $table->timestampTz('incluido_en')->useCurrent();
            $table->primary(['oferta_id', 'animal_id']);
            $table->index('animal_id');
        });

        Schema::create('oferta_publicaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('oferta_id')->constrained('ofertas')->restrictOnDelete();
            $table->integer('version');
            $table->jsonb('contenido');
            $table->char('pdf_sha256', 64)->nullable();
            $table->jsonb('sobre');
            $table->char('hash', 64)->unique();
            $table->foreignUuid('publicado_por')->constrained('usuarios')->restrictOnDelete();
            $table->bigInteger('registro_integridad_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['oferta_id', 'version']);
        });

        Schema::create('perfiles_publicos', function (Blueprint $table) {
            $table->foreignUuid('establecimiento_id')->primary()->constrained('establecimientos')->restrictOnDelete();
            $table->boolean('publicado')->default(false);
            $table->boolean('en_directorio')->default(false);
            $table->boolean('indexable')->default(false);
            $table->timestampTz('preview_aceptada_en')->nullable();
            $table->timestampTz('primera_publicacion_en')->nullable();
            $table->timestampTz('publicado_en')->nullable();
            $table->jsonb('secciones')->default('{}');
            $table->jsonb('textos')->default('{}');
            $table->jsonb('contacto_visible')->default('{"telefono": false, "email": false, "whatsapp": false}');
            $table->text('ubicacion_publica')->default('departamento');
            $table->boolean('asociaciones_visibles')->default(true);
            $table->smallInteger('completitud')->default(0);
            $table->integer('publicacion_vigente')->nullable();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE perfiles_publicos ADD COLUMN slug citext NOT NULL UNIQUE');
        DB::statement("ALTER TABLE perfiles_publicos ADD COLUMN idiomas text[] NOT NULL DEFAULT '{es,en,pt}'");
        DB::statement('ALTER TABLE perfiles_publicos ADD COLUMN fotos char(64)[] NULL');
        DB::statement("ALTER TABLE perfiles_publicos ADD CONSTRAINT perfiles_slug CHECK (slug ~ '^[a-z0-9-]{3,60}$')");
        DB::statement('ALTER TABLE perfiles_publicos ADD CONSTRAINT perfiles_preview CHECK (NOT publicado OR preview_aceptada_en IS NOT NULL)');
        DB::statement("ALTER TABLE perfiles_publicos ADD CONSTRAINT perfiles_ubicacion CHECK (ubicacion_publica IN ('provincia','departamento','localidad'))");

        Schema::create('perfil_slugs_historicos', function (Blueprint $table) {
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->timestampTz('hasta');
        });
        DB::statement('ALTER TABLE perfil_slugs_historicos ADD COLUMN slug citext PRIMARY KEY');

        Schema::create('perfil_publicaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->integer('version');
            $table->jsonb('contenido');
            $table->jsonb('sobre');
            $table->char('hash', 64)->unique();
            $table->foreignUuid('publicado_por')->constrained('usuarios')->restrictOnDelete();
            $table->bigInteger('registro_integridad_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['establecimiento_id', 'version']);
        });

        foreach (['oferta_publicaciones', 'perfil_publicaciones'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_inmutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()");
            DB::statement("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()");
        }
    }

    public function down(): void
    {
        foreach (['perfil_publicaciones', 'perfil_slugs_historicos', 'perfiles_publicos', 'oferta_publicaciones', 'oferta_animales', 'ofertas'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
