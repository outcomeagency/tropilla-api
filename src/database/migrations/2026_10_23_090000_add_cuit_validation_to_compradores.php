<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compradores', function (Blueprint $table) {
            $table->text('cuit_cifrado')->nullable();
            $table->char('cuit_hash', 64)->nullable()->unique();
            $table->text('validacion_cuit_estado')->default('pendiente');
            $table->text('validacion_cuit_fuente')->nullable();
            $table->timestampTz('validacion_cuit_en')->nullable();
            $table->text('razon_social_informada')->nullable();
            $table->text('razon_social_declarada')->nullable();
            $table->boolean('consentimiento_cuit')->default(false);
            $table->timestampTz('consentimiento_cuit_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('compradores', function (Blueprint $table) {
            $table->dropUnique(['cuit_hash']);
            $table->dropColumn(['cuit_cifrado', 'cuit_hash', 'validacion_cuit_estado', 'validacion_cuit_fuente', 'validacion_cuit_en', 'razon_social_informada', 'razon_social_declarada', 'consentimiento_cuit', 'consentimiento_cuit_en']);
        });
    }
};
