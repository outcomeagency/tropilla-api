<?php

use Illuminate\Support\Facades\DB;

it('habilita PostGIS y pgcrypto por migración', function () {
    $extensiones = DB::table('pg_extension')->pluck('extname');

    expect($extensiones)->toContain('postgis', 'pgcrypto');
    expect(DB::scalar('select postgis_version()'))->toBeString()->not->toBeEmpty();
});

it('calcula geometrías con PostGIS', function () {
    // Distancia aproximada Corrientes–Resistencia (~20 km) en metros.
    $metros = DB::scalar(
        'select ST_Distance(ST_MakePoint(-58.8341, -27.4806)::geography, ST_MakePoint(-58.9867, -27.4514)::geography)'
    );

    expect((float) $metros)->toBeGreaterThan(10_000)->toBeLessThan(30_000);
});

it('calcula SHA-256 con pgcrypto igual que PHP', function () {
    $hash = DB::scalar("select encode(digest('tropilla', 'sha256'), 'hex')");

    expect($hash)->toBe(hash('sha256', 'tropilla'));
});
