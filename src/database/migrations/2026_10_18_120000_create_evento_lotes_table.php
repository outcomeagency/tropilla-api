<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-35 (bugfix): lotes anónimos que el servidor resuelve para un evento cuyo sujeto no es el lote (aplicación sanitaria
 * a un potrero → lotes anónimos presentes al registrar). Gemelo de `evento_animales`: append-only, fijo al registrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_lotes', function (Blueprint $table) {
            $table->foreignUuid('evento_id')->constrained('eventos')->restrictOnDelete();
            $table->foreignUuid('lote_id')->constrained('lotes')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->timestampTz('ocurrido_en');
            $table->primary(['evento_id', 'lote_id']);
            $table->index(['lote_id', 'ocurrido_en']);
        });
        DB::statement('CREATE TRIGGER evento_lotes_inmutable BEFORE UPDATE OR DELETE ON evento_lotes FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
        DB::statement('CREATE TRIGGER evento_lotes_no_truncate BEFORE TRUNCATE ON evento_lotes FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_lotes');
    }
};
