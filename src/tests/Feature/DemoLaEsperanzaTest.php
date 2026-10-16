<?php

use App\Models\User;
use Database\Seeders\DemoLaEsperanzaSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

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

    Sanctum::actingAs(User::query()->where('email', 'demo@tropilla.ar')->sole());
    // Reproducción (TPA-30): 4 búfalas con parto en ≤ 7 días, una vaca IATF y una con el parto vencido.
    $tablero = collect($this->getJson("/api/v1/establecimientos/{$u0142->establecimiento_id}/reproduccion/tablero")->assertOk()->json('data'))->keyBy('especie');
    expect($tablero['BOV'])->toMatchArray(['servidas' => 1, 'prenadas' => 1, 'vacias' => 1, 'partos_vencidos' => 1])
        ->and($tablero['BUB']['prenadas'])->toBe(5);
    $hoy = now('America/Argentina/Buenos_Aires');
    $this->getJson("/api/v1/establecimientos/{$u0142->establecimiento_id}/reproduccion/partos?desde={$hoy->toDateString()}&hasta={$hoy->copy()->addDays(7)->toDateString()}&especie=BUB")
        ->assertOk()->assertJsonCount(4, 'data');

    // Plan sanitario (TPA-33): 7 ítems desde plantillas con el aviso aceptado; refuerzo de rabia de los bucerros pendiente.
    $this->getJson("/api/v1/establecimientos/{$u0142->establecimiento_id}/plan-sanitario")->assertOk()->assertJsonCount(7, 'data.items')
        ->assertJsonPath('data.aviso_aceptado_por.nombre', 'Ramón Gómez');
    expect(DB::table('tareas')->where('clave_ocurrencia', 'like', 'refuerzo:%')->where('estado', 'pendiente')->count())->toBe(1)
        ->and(DB::table('productos_sanitarios')->count())->toBe(3);

    // Contrato a futuro de ejemplo (TPA-56): 60 bubillas → 143 tokens, publicado; las bubillas quedan comprometidas.
    expect(DB::table('contratos_futuro')->where('estado', 'parcialmente_tomado')->value('tokens_totales'))->toBe(143)
        ->and(DB::table('tomas')->where('estado', 'confirmada')->value('tokens'))->toBe(40);

    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();

    // idempotente
    $eventos = DB::table('eventos')->count();
    $this->seed(DemoLaEsperanzaSeeder::class);
    expect(DB::table('eventos')->count())->toBe($eventos);
});
