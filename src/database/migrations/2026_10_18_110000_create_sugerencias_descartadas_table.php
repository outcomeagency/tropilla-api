<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-25 (bugfix): «descartar sugerencia» de recategorización. Una fila por (animal, categoría descartada): mientras
 * las reglas sugieran esa categoría para ese animal, no se sugiere. Es una preferencia de manejo, no un hecho del
 * animal: no va a la cadena de eventos (queda en `auditoria` y viaja por pull como cambio del animal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sugerencias_descartadas', function (Blueprint $table) {
            $table->foreignUuid('animal_id')->constrained('animales')->restrictOnDelete();
            $table->text('categoria_codigo');
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('descartada_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('descartada_en')->useCurrent();
            $table->primary(['animal_id', 'categoria_codigo']);
            $table->foreign('categoria_codigo')->references('codigo')->on('categorias')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sugerencias_descartadas');
    }
};
