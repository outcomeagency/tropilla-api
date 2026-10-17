<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reproductores_externos', function (Blueprint $table) {
            $table->date('fecha_nacimiento')->nullable();
            $table->decimal('peso_kg', 7, 1)->nullable();
            $table->date('peso_fecha')->nullable();
            $table->decimal('cc_valor', 4, 2)->nullable();
            $table->unsignedSmallInteger('partos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reproductores_externos', fn (Blueprint $table) => $table->dropColumn(['fecha_nacimiento', 'peso_kg', 'peso_fecha', 'cc_valor', 'partos']));
    }
};
