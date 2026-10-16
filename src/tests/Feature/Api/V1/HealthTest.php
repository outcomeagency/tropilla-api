<?php

use Illuminate\Support\Facades\Redis;

it('responde 200 con todas las dependencias sanas', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.version', 'v1')
        ->assertJsonPath('data.checks.database.ok', true)
        ->assertJsonPath('data.checks.postgis.ok', true)
        ->assertJsonPath('data.checks.redis.ok', true)
        ->assertJsonStructure([
            'data' => ['status', 'app', 'version', 'time', 'checks' => [
                'database' => ['ok', 'latencia_ms', 'detalle'],
                'postgis' => ['ok', 'latencia_ms', 'detalle'],
                'redis' => ['ok', 'latencia_ms', 'detalle'],
            ]],
        ]);
});

it('responde 503 si una dependencia falla', function () {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('redis caído'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('data.status', 'degraded')
        ->assertJsonPath('data.checks.redis.ok', false)
        ->assertJsonPath('data.checks.database.ok', true);
});

it('no expone el detalle del error sin APP_DEBUG', function () {
    config(['app.debug' => false]);
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('secreto interno'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('data.checks.redis.detalle', null);
});
