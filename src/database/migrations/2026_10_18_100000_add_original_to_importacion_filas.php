<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TPA-21 (bugfix): valor original de cada fila tal como vino en la planilla (aditivo; filas viejas quedan null). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importacion_filas', function (Blueprint $table) {
            $table->jsonb('original')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('importacion_filas', function (Blueprint $table) {
            $table->dropColumn('original');
        });
    }
};
