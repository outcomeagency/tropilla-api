<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-77 — Bot de Telegram del productor (@tropilla_bot). Aditiva:
 * - `telegram_codigos`: códigos de un solo uso y vida corta para vincular (solo el hash SHA-256).
 * - `telegram_vinculos`: chat ↔ usuario (uno activo por usuario y por chat), establecimiento activo y avisos on/off.
 * - `telegram_propuestas`: lo que el bot propone y espera confirmación (evento, borrador, elegir campo). Nada se registra sin confirmar.
 * - `telegram_mensajes`: bitácora append-only del bot (tipo, comando, acción, entidad; sin el texto libre del usuario).
 * - `telegram_updates`: update_id ya recibidos (Telegram reintenta el webhook: idempotencia).
 * - `eventos.origen` admite `telegram`; `preferencias_notificacion.telegram` (por defecto sí).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_codigos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->char('codigo_hash', 64)->unique();
            $table->timestampTz('expira_en');
            $table->timestampTz('usado_en')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['usuario_id', 'usado_en']);
        });

        Schema::create('telegram_vinculos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->bigInteger('chat_id');
            $table->bigInteger('telegram_usuario_id');
            $table->text('username')->nullable();
            $table->text('telefono')->nullable();
            $table->foreignUuid('establecimiento_id')->nullable()->constrained('establecimientos')->restrictOnDelete();
            $table->boolean('avisos')->default(true);
            $table->timestampTz('vinculado_en');
            $table->timestampTz('desvinculado_en')->nullable();
            $table->text('desvinculado_por')->nullable();
            $table->timestampTz('ultimo_mensaje_en')->nullable();
            $table->timestampsTz();
            $table->index('usuario_id');
        });
        DB::statement('CREATE UNIQUE INDEX telegram_vinculos_chat_activo ON telegram_vinculos (chat_id) WHERE desvinculado_en IS NULL');
        DB::statement('CREATE UNIQUE INDEX telegram_vinculos_usuario_activo ON telegram_vinculos (usuario_id) WHERE desvinculado_en IS NULL');
        DB::statement("ALTER TABLE telegram_vinculos ADD CONSTRAINT telegram_vinculos_desvinculado_por CHECK (desvinculado_por IS NULL OR desvinculado_por IN ('usuario_bot','usuario_panel','nuevo_vinculo','sistema'))");

        Schema::create('telegram_propuestas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vinculo_id')->constrained('telegram_vinculos')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->nullable()->constrained('establecimientos')->restrictOnDelete();
            $table->text('tipo');
            $table->text('estado')->default('pendiente');
            $table->jsonb('datos')->default('{}');
            $table->uuid('borrador_id')->nullable();
            $table->uuid('evento_id')->nullable();
            $table->bigInteger('mensaje_id')->nullable();
            $table->timestampTz('expira_en');
            $table->timestampsTz();
            $table->index(['vinculo_id', 'estado']);
            $table->index('borrador_id');
        });
        DB::statement("ALTER TABLE telegram_propuestas ADD CONSTRAINT telegram_propuestas_tipo CHECK (tipo IN ('evento','borrador'))");
        DB::statement("ALTER TABLE telegram_propuestas ADD CONSTRAINT telegram_propuestas_estado CHECK (estado IN ('pendiente','leyendo','revisando','confirmada','cancelada','expirada','fallida'))");

        Schema::create('telegram_mensajes', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignUuid('vinculo_id')->nullable()->constrained('telegram_vinculos')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->uuid('establecimiento_id')->nullable();
            $table->bigInteger('chat_id');
            $table->text('direccion');
            $table->text('tipo');
            $table->text('accion')->nullable();
            $table->text('entidad_tipo')->nullable();
            $table->uuid('entidad_id')->nullable();
            $table->jsonb('detalle')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['usuario_id', 'created_at']);
            $table->index(['chat_id', 'created_at']);
        });
        DB::statement("ALTER TABLE telegram_mensajes ADD CONSTRAINT telegram_mensajes_direccion CHECK (direccion IN ('entrante','saliente'))");
        DB::statement('CREATE TRIGGER telegram_mensajes_inmutable BEFORE UPDATE OR DELETE ON telegram_mensajes FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');

        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->bigInteger('update_id')->primary();
            $table->timestampTz('recibido_en')->useCurrent();
        });

        DB::statement('ALTER TABLE eventos DROP CONSTRAINT eventos_origen');
        DB::statement("ALTER TABLE eventos ADD CONSTRAINT eventos_origen CHECK (origen IN ('app','web','importacion','sistema','telegram'))");

        Schema::table('preferencias_notificacion', function (Blueprint $table) {
            $table->boolean('telegram')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('preferencias_notificacion', fn (Blueprint $table) => $table->dropColumn('telegram'));
        DB::statement('ALTER TABLE eventos DROP CONSTRAINT eventos_origen');
        DB::statement("ALTER TABLE eventos ADD CONSTRAINT eventos_origen CHECK (origen IN ('app','web','importacion','sistema'))");
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_mensajes');
        Schema::dropIfExists('telegram_propuestas');
        Schema::dropIfExists('telegram_vinculos');
        Schema::dropIfExists('telegram_codigos');
    }
};
