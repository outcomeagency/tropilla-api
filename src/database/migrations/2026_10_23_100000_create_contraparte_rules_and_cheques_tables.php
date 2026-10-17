<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contraparte_reglas', function (Blueprint $table) {
            $table->text('codigo')->primary();
            $table->integer('version');
            $table->text('senal');
            $table->text('operador');
            $table->decimal('umbral', 14, 2)->nullable();
            $table->text('severidad');
            $table->boolean('activa')->default(true);
            $table->text('fuente');
            $table->timestampsTz();
        });
        Schema::create('contraparte_cheque_consultas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->integer('banco_codigo');
            $table->bigInteger('numero_cheque');
            $table->text('cuenta_hash')->nullable();
            $table->jsonb('resultado')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['establecimiento_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contraparte_cheque_consultas');
        Schema::dropIfExists('contraparte_reglas');
    }
};
