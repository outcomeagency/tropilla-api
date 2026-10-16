<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-57 — modelo-datos §2.8/§9.7: cuenta liviana de comprador (1:1 con usuario), seguimientos y clave de idempotencia de tomas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compradores', function (Blueprint $table) {
            $table->foreignUuid('usuario_id')->primary()->constrained('usuarios')->restrictOnDelete();
            $table->text('empresa');
            $table->text('tipo');
            $table->char('pais', 2);
            $table->text('volumen_habitual')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE compradores ADD COLUMN idiomas text[] NOT NULL DEFAULT '{es}'");
        DB::statement("ALTER TABLE compradores ADD CONSTRAINT compradores_tipo CHECK (tipo IN ('frigorifico','exportador','gastronomico','institucion','productor','otro'))");

        Schema::create('seguimientos', function (Blueprint $table) {
            $table->foreignUuid('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('dejado_en')->nullable();
            $table->primary(['usuario_id', 'establecimiento_id']);
        });

        Schema::table('tomas', function (Blueprint $table) {
            $table->text('idempotency_key')->nullable();
            $table->unique(['comprador_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('tomas', fn (Blueprint $t) => $t->dropColumn('idempotency_key'));
        Schema::dropIfExists('seguimientos');
        Schema::dropIfExists('compradores');
    }
};
