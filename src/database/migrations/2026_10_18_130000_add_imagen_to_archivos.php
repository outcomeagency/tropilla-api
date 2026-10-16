<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPA-54 (bugfix): metadatos de imagen en `archivos` (aditivo): dimensiones (se miden al completar la subida o al guardar)
 * y texto alternativo por idioma `{es, en, pt}`. El alt va con el archivo porque el contenido direccionado por hash es la imagen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archivos', function (Blueprint $table) {
            $table->integer('ancho')->nullable();
            $table->integer('alto')->nullable();
            $table->jsonb('alt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('archivos', function (Blueprint $table) {
            $table->dropColumn(['ancho', 'alto', 'alt']);
        });
    }
};
