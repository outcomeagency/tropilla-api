<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reproductores_externos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('establecimiento_id')->index();
            $table->string('nombre');
            $table->string('rp')->nullable();
            $table->string('registro_genealogico')->nullable();
            $table->string('raza')->nullable();
            $table->string('especie_codigo', 32);
            $table->string('centro')->nullable();
            $table->uuid('madre_id')->nullable();
            $table->uuid('padre_id')->nullable();
            $table->uuid('madre_externa_id')->nullable();
            $table->uuid('padre_externa_id')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();
            $table->foreign('establecimiento_id')->references('id')->on('establecimientos');
            $table->foreign('madre_id')->references('id')->on('animales');
            $table->foreign('padre_id')->references('id')->on('animales');
        });
        Schema::create('reproductor_externo_versiones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('reproductor_id')->index();
            $table->uuid('establecimiento_id')->index();
            $table->unsignedInteger('version');
            $table->string('accion', 16);
            $table->jsonb('datos');
            $table->uuid('autor_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['reproductor_id', 'version']);
        });
        DB::statement("CREATE OR REPLACE FUNCTION tropilla_inmutable_reproductor_version() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'reproductor_externo_versiones es append-only'; END $$");
        DB::statement('CREATE TRIGGER reproductor_externo_versiones_inmutable BEFORE UPDATE OR DELETE ON reproductor_externo_versiones FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable_reproductor_version()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS reproductor_externo_versiones_inmutable ON reproductor_externo_versiones');
        DB::statement('DROP FUNCTION IF EXISTS tropilla_inmutable_reproductor_version()');
        Schema::dropIfExists('reproductor_externo_versiones');
        Schema::dropIfExists('reproductores_externos');
    }
};
