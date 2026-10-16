<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-11 — modelo-datos.md §2.6. El token del link nunca se guarda: solo su SHA-256. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->text('telefono')->nullable();
            $table->text('rol');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expira_en');
            $table->timestampTz('aceptada_en')->nullable();
            $table->foreignUuid('aceptada_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestampTz('revocada_en')->nullable();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->timestampsTz();
            $table->index('establecimiento_id');
        });
        DB::statement('ALTER TABLE invitaciones ADD COLUMN email citext NULL');
        DB::statement("ALTER TABLE invitaciones ADD CONSTRAINT invitaciones_rol CHECK (rol IN ('admin','encargado','asesor','lectura'))");
        DB::statement('ALTER TABLE invitaciones ADD CONSTRAINT invitaciones_destino CHECK (email IS NOT NULL OR telefono IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitaciones');
    }
};
