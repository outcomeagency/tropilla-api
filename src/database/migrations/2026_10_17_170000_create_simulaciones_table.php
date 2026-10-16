<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-45 — modelo-datos §9.1: simulaciones guardadas (privadas, inmutables: una nueva por cálculo). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->text('nombre')->nullable();
            $table->text('especie_codigo')->nullable();
            $table->text('categoria_codigo')->nullable();
            $table->integer('cantidad')->nullable();
            $table->jsonb('entradas');
            $table->jsonb('resultado');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['establecimiento_id', 'created_at']);
        });
        DB::statement('CREATE TRIGGER simulaciones_inmutable BEFORE UPDATE OR DELETE ON simulaciones FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('simulaciones');
    }
};
