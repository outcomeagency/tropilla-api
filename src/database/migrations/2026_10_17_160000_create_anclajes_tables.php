<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-40 — modelo-datos §8: anclajes (raíz Merkle sellada por un AnchorDriver) e inclusiones (append-only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anclajes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('driver');
            $table->text('red')->nullable();
            $table->char('raiz', 64);
            $table->integer('hojas');
            $table->unsignedBigInteger('desde_registro_id');
            $table->unsignedBigInteger('hasta_registro_id');
            $table->text('estado')->default('pendiente');
            $table->smallInteger('intentos')->default(0);
            $table->timestampTz('enviado_en')->nullable();
            $table->timestampTz('confirmado_en')->nullable();
            $table->jsonb('referencia')->nullable();
            $table->text('recibo_clave')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->index('estado');
        });
        DB::statement("ALTER TABLE anclajes ADD CONSTRAINT anclajes_estado CHECK (estado IN ('pendiente','enviado','confirmado','fallido'))");

        Schema::create('anclaje_inclusiones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registro_id');
            $table->foreign('registro_id')->references('id')->on('registros_integridad')->restrictOnDelete();
            $table->foreignUuid('anclaje_id')->constrained('anclajes')->restrictOnDelete();
            $table->integer('indice_hoja');
            $table->jsonb('prueba');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['registro_id', 'anclaje_id']);
            $table->index('registro_id');
        });
        DB::statement('CREATE TRIGGER anclaje_inclusiones_inmutable BEFORE UPDATE OR DELETE ON anclaje_inclusiones FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
        DB::statement('CREATE TRIGGER anclaje_inclusiones_no_truncate BEFORE TRUNCATE ON anclaje_inclusiones FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('anclaje_inclusiones');
        Schema::dropIfExists('anclajes');
    }
};
