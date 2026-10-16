<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ofertas DROP CONSTRAINT ofertas_tipo');
        DB::statement("ALTER TABLE ofertas ADD CONSTRAINT ofertas_tipo CHECK (tipo IN ('lote','invernada','gordo','cria','reproductores'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ofertas DROP CONSTRAINT ofertas_tipo');
        DB::statement("ALTER TABLE ofertas ADD CONSTRAINT ofertas_tipo CHECK (tipo IN ('invernada','gordo','cria','reproductores'))");
    }
};
