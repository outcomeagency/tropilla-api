<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cambios DROP CONSTRAINT cambios_entidad');
        DB::statement("ALTER TABLE cambios ADD CONSTRAINT cambios_entidad CHECK (entidad IN ('evento','animal','lote','potrero','documento','producto_sanitario','tarea','revision','membresia','establecimiento','anclaje','plan_item','reproductor_externo'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cambios DROP CONSTRAINT cambios_entidad');
        DB::statement("ALTER TABLE cambios ADD CONSTRAINT cambios_entidad CHECK (entidad IN ('evento','animal','lote','potrero','documento','producto_sanitario','tarea','revision','membresia','establecimiento','anclaje','plan_item'))");
    }
};
