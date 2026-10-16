<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE establecimientos ADD COLUMN lindero geometry(MultiPolygon,4326) NULL');
        DB::statement('CREATE INDEX establecimientos_lindero_gist ON establecimientos USING GIST (lindero)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS establecimientos_lindero_gist');
        DB::statement('ALTER TABLE establecimientos DROP COLUMN lindero');
    }
};
