<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establecimientos', function (Blueprint $table) {
            $table->jsonb('ficha_ampliada')->default('{}');
            $table->jsonb('visibilidad_ficha')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('establecimientos', function (Blueprint $table) {
            $table->dropColumn(['ficha_ampliada', 'visibilidad_ficha']);
        });
    }
};
