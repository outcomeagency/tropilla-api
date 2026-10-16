<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-44 — modelo-datos §10 `subidas` (subida diferida simple o por partes, con vencimiento). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subidas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('archivo_sha256', 64);
            $table->foreign('archivo_sha256')->references('sha256')->on('archivos')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->uuid('dispositivo_id')->nullable();
            $table->text('modo');
            $table->text('s3_upload_id')->nullable();
            $table->integer('partes')->default(1);
            $table->bigInteger('parte_bytes')->nullable();
            $table->text('estado')->default('iniciada');
            $table->timestampTz('expira_en');
            $table->timestampsTz();
            $table->index(['estado', 'expira_en']);
        });
        DB::statement("ALTER TABLE subidas ADD CONSTRAINT subidas_modo CHECK (modo IN ('simple','multipart'))");
        DB::statement("ALTER TABLE subidas ADD CONSTRAINT subidas_estado CHECK (estado IN ('iniciada','completada','abortada','expirada'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('subidas');
    }
};
