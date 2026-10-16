<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-9 — modelo-datos.md §2.1 (usuarios) y §2.2 (dispositivos).
 *
 * Aditiva: la tabla `users` del esqueleto de Laravel (id bigint) queda sin uso.
 * La identidad del dominio vive en `usuarios` (uuid v7), como el resto del modelo.
 * `personal_access_tokens.tokenable_id` se ensancha de bigint a varchar(36) para
 * aceptar uuids (conversión sin pérdida: bigint -> texto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('nombre');
            $table->text('telefono')->nullable();
            $table->text('password');
            $table->timestampTz('email_verificado_en')->nullable();
            $table->timestampTz('telefono_verificado_en')->nullable();
            $table->text('idioma')->default('es');
            $table->char('foto_sha256', 64)->nullable();
            $table->char('pais', 2)->nullable();
            $table->timestampTz('ultimo_acceso_en')->nullable();
            $table->timestampTz('bloqueado_en')->nullable();
            $table->boolean('es_staff')->default(false);
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE usuarios ADD COLUMN email citext NULL');
        DB::statement('CREATE UNIQUE INDEX usuarios_email_unico ON usuarios (email) WHERE email IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX usuarios_telefono_unico ON usuarios (telefono) WHERE telefono IS NOT NULL');
        DB::statement('ALTER TABLE usuarios ADD CONSTRAINT usuarios_email_o_telefono CHECK (email IS NOT NULL OR telefono IS NOT NULL)');
        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT usuarios_idioma CHECK (idioma IN ('es','en','pt'))");
        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT usuarios_foto_sha256 CHECK (foto_sha256 ~ '^[0-9a-f]{64}$')");

        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE varchar(36) USING tokenable_id::varchar');

        Schema::create('dispositivos', function (Blueprint $table) {
            $table->uuid('id')->primary(); // generado en el teléfono al instalar
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('plataforma');
            $table->text('nombre')->nullable();
            $table->text('app_version')->nullable();
            $table->text('fcm_token')->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->timestampTz('ultimo_push_en')->nullable();
            $table->timestampTz('ultimo_pull_en')->nullable();
            $table->timestampTz('revocado_en')->nullable();
            $table->timestampsTz();

            $table->index('usuario_id');
            $table->foreign('token_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
        });
        DB::statement("ALTER TABLE dispositivos ADD CONSTRAINT dispositivos_plataforma CHECK (plataforma IN ('android','ios','web'))");

        // Restablecimiento de contraseña por token (el body de /auth/password/restablecer
        // trae solo {token, password}: se busca por hash del token, no por email).
        Schema::create('restablecimientos_password', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expira_en');
            $table->timestampTz('usado_en')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restablecimientos_password');
        Schema::dropIfExists('dispositivos');
        Schema::dropIfExists('usuarios');
    }
};
