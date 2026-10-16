<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    Http::fake(['*' => Http::response('%PDF-1.4 x', 200)]);
    $this->travelTo('2026-10-12 15:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(duenoDe($this->est));
});

function venta(object $t, string $fecha, string $precio, string $categoria = 'BUB_BUBILLO', string $modalidad = 'en_pie', ?string $ref = null): string
{
    return $t->postJson("{$t->base}/operaciones", array_filter(['tipo' => 'venta', 'fecha' => $fecha, 'especie' => str_starts_with($categoria, 'BUB') ? 'BUB' : 'BOV',
        'categoria' => $categoria, 'cantidad' => 10, 'trato' => ['modalidad' => $modalidad], 'liquidacion' => ['peso_bruto_kg' => '4500', 'precio_kg' => $precio],
        'precio_referencia_id' => $ref, 'generar_pdf' => false]))->assertCreated()->json('data.id');
}

function referencia(object $t, array $d): string
{
    return $t->postJson("{$t->base}/precios-referencia", array_merge(['modalidad' => 'en_pie', 'moneda' => 'ARS', 'fuente' => 'Mercado Agroganadero (declarado)'], $d))
        ->assertCreated()->json('data.id');
}

it('precio por operación, especie y categoría contra la referencia declarada; diferencial % y promedio', function () {
    referencia($this, ['descripcion' => 'Bubillo gordo zona', 'especie' => 'BUB', 'categoria' => 'BUB_BUBILLO', 'precio_kg' => '2400', 'fecha' => '2026-08-01']);
    referencia($this, ['descripcion' => 'Bubillo gordo zona', 'especie' => 'BUB', 'categoria' => 'BUB_BUBILLO', 'precio_kg' => '2500', 'fecha' => '2026-10-01']);
    $overo = referencia($this, ['descripcion' => 'Novillo overo', 'especie' => 'BOV', 'categoria' => 'BOV_NOVILLO', 'precio_kg' => '2600', 'fecha' => '2026-09-01']);
    venta($this, '2026-08-20', '2520');                       // vs 2400 → +5 %
    venta($this, '2026-10-05', '2450');                       // vs 2500 → −2 %
    venta($this, '2026-10-06', '4700', 'BUB_BUBILLO', 'al_gancho'); // sin referencia al gancho → null
    venta($this, '2026-10-07', '2300', 'BOV_NOVILLO');        // vs overo 2600 → −11,54 %

    $h = collect($this->getJson("{$this->base}/precios/historial")->assertOk()->json('data'))->keyBy('categoria');
    expect(array_column($h['BUB_BUBILLO']['puntos'], 'diferencial_pct'))->toBe(['5', '-2', null])
        ->and(array_column($h['BUB_BUBILLO']['puntos'], 'referencia_kg'))->toBe(['2400', '2500', null])
        ->and($h['BUB_BUBILLO']['diferencial_promedio_pct'])->toBe('1.5')
        ->and($h['BOV_NOVILLO']['puntos'][0]['diferencial_pct'])->toBe('-11.54');

    // El búfalo contra el «novillo overo» (otra especie, por descripción).
    $vs = $this->getJson("{$this->base}/precios/historial?especie=BUB&referencia=novillo overo")->json('data.0.puntos');
    expect(array_column($vs, 'referencia_kg'))->toBe([null, '2600', null])->and($vs[1]['diferencial_pct'])->toBe('-5.77');
    // Referencia elegida en la operación.
    venta($this, '2026-10-08', '2860', 'BUB_BUBILLO', 'en_pie', $overo);
    expect(collect($this->getJson("{$this->base}/precios/historial?desde=2026-10-08")->json('data.0.puntos'))->first()['diferencial_pct'])->toBe('10');
    // Las anuladas no cuentan.
    $id = DB::table('operaciones')->orderByDesc('id')->value('id');
    $this->postJson("{$this->base}/operaciones/{$id}/anulaciones", ['motivo' => 'No se concretó'])->assertCreated();
    $this->getJson("{$this->base}/precios/historial?desde=2026-10-08")->assertJsonCount(0, 'data');

    $this->getJson("{$this->base}/precios-referencia?especie=BUB")->assertJsonCount(2, 'data')->assertJsonPath('data.0.precio_kg', '2500');
});

it('solo dueño/admin; 422; 404 ajeno; referencias inmutables', function () {
    $this->postJson("{$this->base}/precios-referencia", ['descripcion' => 'x', 'especie' => 'BUB', 'categoria' => 'BOV_VACA', 'modalidad' => 'colgado', 'precio_kg' => '0', 'fecha' => '2030-01-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['descripcion', 'categoria', 'modalidad', 'precio_kg', 'fecha']);
    referencia($this, ['descripcion' => 'Vaca gorda', 'especie' => 'BOV', 'precio_kg' => '1900', 'fecha' => '2026-10-01']);
    expect(fn () => DB::transaction(fn () => DB::table('precios_referencia')->update(['precio_kg' => 1])))->toThrow(Exception::class);
    Sanctum::actingAs(miembro($this->est, 'admin'));
    $this->getJson("{$this->base}/precios/historial")->assertOk();
    foreach (['encargado', 'asesor', 'lectura'] as $rol) {
        Sanctum::actingAs(miembro($this->est, $rol));
        $this->getJson("{$this->base}/precios/historial")->assertForbidden();
        $this->getJson("{$this->base}/precios-referencia")->assertForbidden();
    }
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/precios/historial")->assertNotFound();
});
