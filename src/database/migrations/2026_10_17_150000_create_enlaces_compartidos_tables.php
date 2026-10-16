<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TPA-38 — modelo-datos §10: enlaces que vencen (token solo en la URL; se guarda su SHA-256) y accesos append-only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enlaces_compartidos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('documento_id')->constrained('documentos')->restrictOnDelete();
            $table->foreignUuid('establecimiento_id')->constrained('establecimientos')->restrictOnDelete();
            $table->integer('version')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expira_en');
            $table->timestampTz('revocado_en')->nullable();
            $table->foreignUuid('revocado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('creado_por')->constrained('usuarios')->restrictOnDelete();
            $table->integer('accesos_count')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->index('documento_id');
        });

        Schema::create('enlace_accesos', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('enlace_id')->constrained('enlaces_compartidos')->restrictOnDelete();
            $table->timestampTz('accedido_en')->useCurrent();
            $table->char('ip_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
        });
        DB::statement('CREATE TRIGGER enlace_accesos_inmutable BEFORE UPDATE OR DELETE ON enlace_accesos FOR EACH ROW EXECUTE FUNCTION tropilla_inmutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('enlace_accesos');
        Schema::dropIfExists('enlaces_compartidos');
    }
};
