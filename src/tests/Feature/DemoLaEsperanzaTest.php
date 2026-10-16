<?php

use Database\Seeders\DemoLaEsperanzaSeeder;
use Illuminate\Support\Facades\DB;

it('carga el demo de La Esperanza por eventos reales, con las existencias del brief y cadena íntegra', function () {
    $this->seed(DemoLaEsperanzaSeeder::class);

    $this->postJson('/api/v1/auth/login', ['email' => 'demo@tropilla.ar', 'password' => 'tropilla-demo', 'cliente' => 'web'])
        ->assertOk()->assertJsonPath('data.membresias.0.establecimiento_nombre', 'La Esperanza')
        ->assertJsonPath('data.membresias.0.rol', 'dueno');

    $totales = DB::table('existencias')->groupBy('especie_codigo')->selectRaw('especie_codigo, sum(cabezas) as n')->pluck('n', 'especie_codigo')->all();
    expect((int) $totales['BOV'])->toBe(1240)->and((int) $totales['BUB'])->toBe(380);

    $u0142 = DB::table('animales_estado')->where('identificacion_principal', '0142')->first();
    expect($u0142)->estado_reproductivo->toBe('prenada')->fecha_probable_parto->toBe('2027-01-12')->peso_kg->toBe('590.0');
    expect(DB::table('animales_estado')->whereNotNull('categoria_sugerida_codigo')->count())->toBe(8);
    expect(DB::table('lotes_estado')->where('peso_promedio_kg', '468.0')->count())->toBe(1);

    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();

    // idempotente
    $eventos = DB::table('eventos')->count();
    $this->seed(DemoLaEsperanzaSeeder::class);
    expect(DB::table('eventos')->count())->toBe($eventos);
});
