<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-22 — Hacienda y eventos (modelo-datos.md §4, §6, §12 revisiones):
 * lotes (maestro), animales (identidad), eventos + evento_animales (append-only), animal_identificaciones,
 * y proyecciones (animales_estado, lotes_estado, lote_conteos, existencias). Las proyecciones son caché
 * recalculable desde `eventos_vigentes`: se pueden reconstruir en cualquier momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('nombre');
            $table->text('especie_codigo');
            $table->text('proposito')->nullable();
            $table->boolean('anonimo')->default(false);
            $table->text('notas')->nullable();
            $table->integer('row_version')->default(1);
            $table->foreignUuid('creado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampsTz();
            $table->timestampTz('archivado_en')->nullable();
            $table->foreignUuid('archivado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->foreign('especie_codigo')->references('codigo')->on('especies')->restrictOnDelete();
            $table->index('establecimiento_id');
        });
        DB::statement("ALTER TABLE lotes ADD CONSTRAINT lotes_proposito CHECK (proposito IN ('cria','recria','engorde','reproductores','venta','descarte'))");

        Schema::create('eventos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->uuid('evento_raiz_id');
            $table->smallInteger('version');
            $table->text('accion');
            $table->uuid('corrige_evento_id')->nullable();
            $table->text('motivo')->nullable();
            $table->boolean('en_revision')->default(false);
            $table->text('tipo');
            $table->smallInteger('tipo_version');
            $table->text('sujeto_tipo');
            $table->foreignUuid('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();
            $table->foreignUuid('potrero_id')->nullable()->constrained('potreros')->restrictOnDelete();
            $table->jsonb('conteo')->nullable();
            $table->jsonb('payload');
            $table->timestampTz('ocurrido_en');
            $table->text('ocurrido_precision');
            $table->timestampTz('creado_en');
            $table->timestampTz('registrado_en');
            $table->foreignUuid('autor_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('dispositivo_id')->nullable()->constrained('dispositivos')->restrictOnDelete();
            $table->text('origen');
            $table->text('responsable')->nullable();
            $table->jsonb('sobre');
            $table->char('hash', 64)->unique();
            $table->char('hash_anterior', 64)->nullable();
            $table->bigInteger('registro_integridad_id');
            $table->foreign(['tipo', 'tipo_version'])->references(['tipo_codigo', 'version'])->on('tipos_evento_versiones')->restrictOnDelete();
            $table->foreign('registro_integridad_id')->references('id')->on('registros_integridad')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE eventos ADD COLUMN ubicacion_carga geography(Point,4326) NULL');
        DB::statement('ALTER TABLE eventos ADD COLUMN adjuntos char(64)[] NULL');
        DB::statement('ALTER TABLE eventos ADD COLUMN observaciones_validacion text[] NULL');
        DB::statement('ALTER TABLE eventos ADD CONSTRAINT eventos_raiz_fk FOREIGN KEY (evento_raiz_id) REFERENCES eventos(id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE eventos ADD CONSTRAINT eventos_corrige_fk FOREIGN KEY (corrige_evento_id) REFERENCES eventos(id)');
        DB::statement("ALTER TABLE eventos ADD CONSTRAINT eventos_accion CHECK (accion IN ('alta','correccion','anulacion'))");
        DB::statement("ALTER TABLE eventos ADD CONSTRAINT eventos_version_coherente CHECK (
            (version = 1 AND accion = 'alta' AND corrige_evento_id IS NULL AND hash_anterior IS NULL)
            OR (version > 1 AND accion IN ('correccion','anulacion') AND corrige_evento_id IS NOT NULL
                AND hash_anterior IS NOT NULL AND length(trim(motivo)) > 0))");
        DB::statement("ALTER TABLE eventos ADD CONSTRAINT eventos_raiz_alta CHECK (accion = 'alta' AND id = evento_raiz_id OR accion <> 'alta')");
        DB::statement("ALTER TABLE eventos ADD CONSTRAINT eventos_sujeto CHECK (sujeto_tipo IN ('animales','lote','potrero','establecimiento'))");
        DB::statement("ALTER TABLE eventos ADD CONSTRAINT eventos_precision CHECK (ocurrido_precision IN ('dia','minuto'))");
        DB::statement("ALTER TABLE eventos ADD CONSTRAINT eventos_origen CHECK (origen IN ('app','web','importacion','sistema'))");
        DB::statement("ALTER TABLE eventos ADD CONSTRAINT eventos_hash_hex CHECK (hash ~ '^[0-9a-f]{64}$')");
        DB::statement('CREATE UNIQUE INDEX eventos_version_vigente ON eventos (evento_raiz_id, version) WHERE NOT en_revision');
        DB::statement('CREATE INDEX eventos_timeline_est ON eventos (establecimiento_id, ocurrido_en DESC)');
        DB::statement('CREATE INDEX eventos_tipo ON eventos (establecimiento_id, tipo, ocurrido_en DESC)');
        DB::statement('CREATE INDEX eventos_lote ON eventos (lote_id, ocurrido_en) WHERE lote_id IS NOT NULL');
        DB::statement('CREATE INDEX eventos_raiz ON eventos (evento_raiz_id, version)');
        DB::statement('CREATE INDEX eventos_payload_gin ON eventos USING gin (payload jsonb_path_ops)');

        Schema::create('animales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('especie_codigo');
            $table->foreignUuid('evento_origen_raiz_id')->constrained('eventos')->restrictOnDelete();
            $table->foreignUuid('lote_origen_anonimo_id')->nullable()->constrained('lotes')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('especie_codigo')->references('codigo')->on('especies')->restrictOnDelete();
            $table->index('establecimiento_id');
        });

        Schema::create('evento_animales', function (Blueprint $table) {
            $table->foreignUuid('evento_id')->constrained('eventos')->restrictOnDelete();
            $table->foreignUuid('animal_id')->constrained('animales')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->timestampTz('ocurrido_en');
            $table->primary(['evento_id', 'animal_id']);
        });
        DB::statement('CREATE INDEX evento_animales_timeline ON evento_animales (animal_id, ocurrido_en DESC)');

        foreach (['eventos', 'evento_animales'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_inmutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()");
            DB::statement("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()");
        }

        DB::statement('CREATE VIEW eventos_vigentes AS SELECT DISTINCT ON (evento_raiz_id) * FROM eventos WHERE NOT en_revision ORDER BY evento_raiz_id, version DESC');

        // Proyección: identificaciones (§4.6).
        Schema::create('animal_identificaciones', function (Blueprint $table) {
            $table->id()->generatedAs();
            $table->uuid('establecimiento_id');
            $table->foreignUuid('animal_id')->constrained('animales')->restrictOnDelete();
            $table->text('tipo');
            $table->text('valor');
            $table->text('valor_normalizado');
            $table->text('entidad')->nullable();
            $table->text('tipo_registro')->nullable();
            $table->boolean('vigente')->default(true);
            $table->boolean('en_conflicto')->default(false);
            $table->uuid('desde_evento_id')->nullable();
            $table->uuid('hasta_evento_id')->nullable();
            $table->date('colocada_en')->nullable();
            $table->date('retirada_en')->nullable();
            $table->index('animal_id');
        });
        DB::statement("ALTER TABLE animal_identificaciones ADD CONSTRAINT ident_tipo CHECK (tipo IN ('caravana_visual','caravana_oficial','rfid','tatuaje','marca_senal','registro_genealogico'))");
        DB::statement('CREATE UNIQUE INDEX ident_unica_vigente ON animal_identificaciones (establecimiento_id, tipo, valor_normalizado) WHERE vigente AND NOT en_conflicto');
        DB::statement('CREATE INDEX ident_busqueda_trgm ON animal_identificaciones USING gin (valor_normalizado gin_trgm_ops)');

        // Proyección: estado del animal (§6.1).
        Schema::create('animales_estado', function (Blueprint $table) {
            $table->foreignUuid('animal_id')->primary()->constrained('animales')->restrictOnDelete();
            $table->uuid('establecimiento_id');
            $table->text('especie_codigo');
            $table->char('sexo', 1);
            $table->text('raza_codigo')->nullable();
            $table->jsonb('cruza')->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->boolean('nacimiento_estimado')->default(false);
            $table->boolean('castrado')->nullable();
            $table->text('denticion')->nullable();
            $table->uuid('madre_id')->nullable();
            $table->uuid('padre_id')->nullable();
            $table->text('madre_externa')->nullable();
            $table->text('padre_externo')->nullable();
            $table->text('senas')->nullable();
            $table->char('foto_sha256', 64)->nullable();
            $table->text('origen');
            $table->text('categoria_codigo');
            $table->text('categoria_sugerida_codigo')->nullable();
            $table->bigInteger('categoria_sugerida_regla_id')->nullable();
            $table->timestampTz('categoria_sugerida_en')->nullable();
            $table->text('estado')->default('activo');
            $table->uuid('baja_evento_id')->nullable();
            $table->text('baja_tipo')->nullable();
            $table->date('baja_en')->nullable();
            $table->uuid('lote_id')->nullable();
            $table->uuid('potrero_id')->nullable();
            $table->decimal('peso_kg', 7, 1)->nullable();
            $table->date('peso_fecha')->nullable();
            $table->text('peso_metodo')->nullable();
            $table->decimal('peso_anterior_kg', 7, 1)->nullable();
            $table->date('peso_anterior_fecha')->nullable();
            $table->decimal('gdp_kg_dia', 6, 3)->nullable();
            $table->decimal('cc_valor', 4, 2)->nullable();
            $table->text('cc_escala')->nullable();
            $table->date('cc_fecha')->nullable();
            $table->boolean('destetado')->default(false);
            $table->date('destete_fecha')->nullable();
            $table->decimal('destete_peso_kg', 7, 1)->nullable();
            $table->smallInteger('partos')->default(0);
            $table->date('ultimo_parto_fecha')->nullable();
            $table->text('estado_reproductivo')->nullable();
            $table->date('servicio_fecha')->nullable();
            $table->uuid('servicio_evento_id')->nullable();
            $table->text('servicio_tipo')->nullable();
            $table->date('tacto_fecha')->nullable();
            $table->integer('dias_gestacion_al_tacto')->nullable();
            $table->date('fecha_probable_parto')->nullable();
            $table->text('fpp_precision')->nullable();
            $table->boolean('reactor')->default(false);
            $table->date('reactor_desde')->nullable();
            $table->text('reactor_prueba')->nullable();
            $table->date('retiro_carne_hasta')->nullable();
            $table->date('retiro_leche_hasta')->nullable();
            $table->text('identificacion_principal')->nullable();
            $table->integer('eventos_count')->default(0);
            $table->timestampTz('ultimo_evento_ocurrido_en')->nullable();
            $table->smallInteger('revisiones_abiertas')->default(0);
            $table->smallInteger('proyeccion_version')->default(1);
            $table->timestampTz('actualizado_en');
        });
        DB::statement("ALTER TABLE animales_estado ADD CONSTRAINT animales_estado_estado CHECK (estado IN ('activo','vendido','muerto','faenado','transferido','perdido','anulado'))");
        DB::statement('CREATE INDEX animales_estado_filtros ON animales_estado (establecimiento_id, estado, especie_codigo, categoria_codigo)');
        DB::statement('CREATE INDEX animales_estado_lote ON animales_estado (establecimiento_id, lote_id)');
        DB::statement('CREATE INDEX animales_estado_potrero ON animales_estado (establecimiento_id, potrero_id)');
        DB::statement('CREATE INDEX animales_estado_repro ON animales_estado (establecimiento_id, estado_reproductivo, fecha_probable_parto)');
        DB::statement('CREATE INDEX animales_estado_retiro ON animales_estado (establecimiento_id, retiro_carne_hasta) WHERE retiro_carne_hasta IS NOT NULL');
        DB::statement('CREATE INDEX animales_estado_reactor ON animales_estado (establecimiento_id) WHERE reactor');
        DB::statement('CREATE INDEX animales_estado_sugerencia ON animales_estado (establecimiento_id) WHERE categoria_sugerida_codigo IS DISTINCT FROM categoria_codigo');

        // Proyecciones de lotes y existencias (§6.2).
        Schema::create('lotes_estado', function (Blueprint $table) {
            $table->foreignUuid('lote_id')->primary()->constrained('lotes')->restrictOnDelete();
            $table->uuid('establecimiento_id');
            $table->uuid('potrero_id')->nullable();
            $table->integer('cabezas_individuales')->default(0);
            $table->integer('cabezas_anonimas')->default(0);
            $table->decimal('peso_promedio_kg', 7, 1)->nullable();
            $table->date('peso_promedio_fecha')->nullable();
            $table->text('peso_promedio_metodo')->nullable();
            $table->decimal('gdp_promedio_kg_dia', 6, 3)->nullable();
            $table->timestampTz('actualizado_en');
        });

        Schema::create('lote_conteos', function (Blueprint $table) {
            $table->foreignUuid('lote_id')->constrained('lotes')->restrictOnDelete();
            $table->text('categoria_codigo');
            $table->uuid('establecimiento_id');
            $table->integer('cabezas'); // sin CHECK >= 0 (M13): dos teléfonos offline pueden restar la misma cabeza
            $table->primary(['lote_id', 'categoria_codigo']);
        });

        Schema::create('existencias', function (Blueprint $table) {
            $table->uuid('establecimiento_id');
            $table->text('especie_codigo');
            $table->text('categoria_codigo');
            $table->integer('cabezas');
            $table->integer('individuales');
            $table->integer('anonimas');
            $table->timestampTz('actualizado_en');
            $table->primary(['establecimiento_id', 'especie_codigo', 'categoria_codigo']);
        });

        // §12: pendientes de revisión (viajan por sync).
        Schema::create('revisiones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('tipo');
            $table->text('entidad_tipo');
            $table->uuid('entidad_id');
            $table->jsonb('detalle')->default('{}');
            $table->text('estado')->default('abierta');
            $table->foreignUuid('resuelta_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('resuelta_en')->nullable();
            $table->text('resolucion_motivo')->nullable();
            $table->uuid('resolucion_evento_id')->nullable();
            $table->timestampsTz();
            $table->index(['establecimiento_id', 'estado']);
        });
        DB::statement("ALTER TABLE revisiones ADD CONSTRAINT revisiones_tipo CHECK (tipo IN ('correccion_en_conflicto','documento_en_conflicto','maestro_en_conflicto','identificacion_duplicada','evento_posterior_a_baja','conteo_negativo','carga_post_revocacion'))");
        DB::statement("ALTER TABLE revisiones ADD CONSTRAINT revisiones_estado CHECK (estado IN ('abierta','reaplicada','descartada','resuelta'))");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS eventos_vigentes');
        foreach (['revisiones', 'existencias', 'lote_conteos', 'lotes_estado', 'animales_estado', 'animal_identificaciones',
            'evento_animales', 'animales', 'eventos', 'lotes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
