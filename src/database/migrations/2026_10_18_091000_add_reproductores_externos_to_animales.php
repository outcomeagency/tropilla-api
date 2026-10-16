<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animales', function (Blueprint $table) {
            $table->uuid('madre_externa_id')->nullable()->index();
            $table->uuid('padre_externo_id')->nullable()->index();
        });
        Schema::table('animales_estado', function (Blueprint $table) {
            $table->uuid('madre_externa_id')->nullable()->index();
            $table->uuid('padre_externo_id')->nullable()->index();
        });
        DB::statement('ALTER TABLE animales ADD CONSTRAINT animales_madre_externa_fk FOREIGN KEY (madre_externa_id) REFERENCES reproductores_externos(id)');
        DB::statement('ALTER TABLE animales ADD CONSTRAINT animales_padre_externo_fk FOREIGN KEY (padre_externo_id) REFERENCES reproductores_externos(id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE animales DROP CONSTRAINT IF EXISTS animales_madre_externa_fk');
        DB::statement('ALTER TABLE animales DROP CONSTRAINT IF EXISTS animales_padre_externo_fk');
        Schema::table('animales', fn (Blueprint $table) => $table->dropColumn(['madre_externa_id', 'padre_externo_id']));
        Schema::table('animales_estado', fn (Blueprint $table) => $table->dropColumn(['madre_externa_id', 'padre_externo_id']));
    }
};
