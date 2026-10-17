<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escenarios_climaticos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('creado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->jsonb('datos_fuente');
            $table->jsonb('escenarios');
            $table->jsonb('plan_respuesta');
            $table->text('resumen');
            $table->text('proveedor_ia')->default('fallback');
            $table->text('zona');
            $table->timestampTz('emitido_en');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['establecimiento_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escenarios_climaticos');
    }
};
