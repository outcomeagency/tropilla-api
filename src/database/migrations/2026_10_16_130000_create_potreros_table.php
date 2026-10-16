<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-14 — modelo-datos.md §4.1 (maestro sincronizable; el id puede venir del teléfono). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('potreros', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('nombre');
            $table->decimal('superficie_ha', 10, 2)->nullable();
            $table->text('ambiente')->nullable();
            $table->text('recurso_forrajero')->nullable();
            $table->boolean('agua')->default(false);
            $table->boolean('revolcadero')->default(false);
            $table->text('notas')->nullable();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();
            $table->timestampTz('archivado_en')->nullable();
            $table->foreignUuid('archivado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->index('establecimiento_id');
        });
        DB::statement('ALTER TABLE potreros ADD COLUMN poligono geography(Polygon,4326) NULL');
        DB::statement('CREATE INDEX potreros_poligono ON potreros USING gist (poligono)');
        DB::statement('CREATE UNIQUE INDEX potreros_nombre_unico ON potreros (establecimiento_id, lower(nombre)) WHERE archivado_en IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('potreros');
    }
};
