<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-71 — Brief preventivo de forraje y carga animal.
 * `forraje_briefs`: escenario calculado con el inventario declarado, los supuestos usados (con su origen) y el contexto climático
 * del momento. Inmutable: cambiar un supuesto = brief nuevo.
 * `forraje_brief_revisiones`: revisión humana documentada (piloto con asesor). Append-only; la última define el estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forraje_briefs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->text('nombre')->nullable();
            $table->integer('horizonte_dias');
            $table->jsonb('entradas');
            $table->jsonb('insumos');
            $table->jsonb('resultado');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['establecimiento_id', 'created_at']);
        });
        DB::statement('CREATE TRIGGER forraje_briefs_inmutable BEFORE UPDATE OR DELETE ON forraje_briefs FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');

        Schema::create('forraje_brief_revisiones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('brief_id')->constrained('forraje_briefs')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->foreignUuid('revisor_id')->constrained('usuarios')->restrictOnDelete();
            $table->text('rol');
            $table->text('resultado');
            $table->text('nota');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['brief_id', 'created_at']);
        });
        DB::statement("ALTER TABLE forraje_brief_revisiones ADD CONSTRAINT forraje_brief_revisiones_resultado CHECK (resultado IN ('revisado','con_observaciones'))");
        DB::statement('CREATE TRIGGER forraje_brief_revisiones_inmutable BEFORE UPDATE OR DELETE ON forraje_brief_revisiones FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('forraje_brief_revisiones');
        Schema::dropIfExists('forraje_briefs');
    }
};
