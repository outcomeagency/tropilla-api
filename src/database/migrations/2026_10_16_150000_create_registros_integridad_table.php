<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-39 — modelo-datos.md §8: eslabones de la cadena por establecimiento/productor (append-only).
 * `id` = orden global de inserción (hojas del Merkle de TPA-40).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_integridad', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignUuid('cadena_id')->constrained('cadenas')->restrictOnDelete();
            $table->bigInteger('seq');
            $table->text('sujeto_tipo');
            $table->uuid('sujeto_id');
            $table->char('huella', 64)->index();
            $table->char('eslabon_anterior', 64);
            $table->char('eslabon', 64)->index();
            $table->timestampTz('registrado_en');
            $table->unique(['cadena_id', 'seq']);
            $table->unique(['sujeto_tipo', 'sujeto_id']);
        });
        DB::statement("ALTER TABLE registros_integridad ADD CONSTRAINT registros_integridad_sujeto CHECK (sujeto_tipo IN ('evento','documento_version','contrato_version','toma_movimiento','entrega','oferta_publicacion','operacion_version','consentimiento','perfil_publicacion'))");
        foreach (['huella', 'eslabon_anterior', 'eslabon'] as $c) {
            DB::statement("ALTER TABLE registros_integridad ADD CONSTRAINT registros_integridad_{$c}_hex CHECK ({$c} ~ '^[0-9a-f]{64}$')");
        }
        DB::statement('CREATE TRIGGER registros_integridad_inmutable BEFORE UPDATE OR DELETE ON registros_integridad FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
        DB::statement('CREATE TRIGGER registros_integridad_no_truncate BEFORE TRUNCATE ON registros_integridad FOR EACH STATEMENT EXECUTE FUNCTION tropilla_inmutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_integridad');
    }
};
