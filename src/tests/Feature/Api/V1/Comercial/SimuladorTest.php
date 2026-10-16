<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/simulaciones";
    Sanctum::actingAs(duenoDe($this->est));
});

function entradas30Bubillos(array $extra = []): array
{
    return array_replace_recursive(['especie' => 'BUB', 'categoria' => 'BUB_BUBILLO', 'cantidad' => 30,
        'en_pie' => ['peso_bruto_kg' => '14040', 'desbaste_pct' => '6', 'precio_kg' => '2500'],
        'al_gancho' => ['peso_bruto_kg' => '14040', 'precio_kg' => '4700'],
        'flete' => '450000', 'arranque' => '50000', 'seguro' => '30000', 'comision_pct' => '3'], $extra);
}

it('en pie vs al gancho lado a lado con la fórmula explícita (desbaste solo en pie, rendimiento sobre bruto, gastos del vendedor)', function () {
    $r = $this->postJson("{$this->url}/calcular", ['entradas' => entradas30Bubillos()])->assertOk()->json('data');

    expect($r['en_pie'])->toBe([
        'peso_neto_kg' => '13197.6', 'kg_liquidables' => '13197.6', 'subtotal' => '32994000', 'iva' => '3464370', 'total_facturado' => '36458370',
        'comision' => '989820', 'gastos' => '530000', 'neto_total' => '31474180', 'neto_kg_vivo' => '2241.75',
    ])->and($r['al_gancho'])->toBe([
        // rendimiento por defecto de la especie (parámetro BUB 53 %), sobre el bruto, sin desbaste
        'peso_neto_kg' => '14040', 'kg_liquidables' => '7441.2', 'subtotal' => '34973640', 'iva' => '3672232.2', 'total_facturado' => '38645872.2',
        'comision' => '1049209.2', 'gastos' => '530000', 'neto_total' => '33394430.8', 'neto_kg_vivo' => '2378.52',
    ])->and($r['diferencia_neto'])->toBe('1920250.8')->and($r['conviene'])->toBe('al_gancho')
        ->and($r['formula']['al_gancho'][0])->toContain('no se aplica desbaste');

    // Un desbaste mandado al gancho no se aplica (decisión documentada); IVA 21 % y una sola modalidad.
    $solo = $this->postJson("{$this->url}/calcular", ['entradas' => ['al_gancho' => ['peso_bruto_kg' => '500', 'desbaste_pct' => '8', 'rendimiento_pct' => '55', 'precio_kg' => '4000'], 'iva_pct' => '21']])
        ->assertOk()->json('data');
    expect($solo['en_pie'])->toBeNull()->and($solo['conviene'])->toBeNull()
        ->and($solo['al_gancho'])->toMatchArray(['kg_liquidables' => '275', 'subtotal' => '1100000', 'iva' => '231000', 'neto_total' => '1100000', 'neto_kg_vivo' => '2200']);
    $this->postJson("{$this->url}/calcular", ['entradas' => ['al_gancho' => ['peso_bruto_kg' => '500', 'precio_kg' => '4000'], 'especie' => 'BOV']])
        ->assertJsonPath('data.al_gancho.kg_liquidables', '275'); // BOV 55 %
    expect(DB::table('simulaciones')->count())->toBe(0); // calcular no guarda
});

it('guardar es inmutable y se lista; 422; 403 para quien no ve precios; 404 ajeno', function () {
    $s = $this->postJson($this->url, ['nombre' => 'Bubillos octubre', 'entradas' => entradas30Bubillos()])->assertCreated()
        ->assertJsonPath('data.nombre', 'Bubillos octubre')->assertJsonPath('data.resultado.conviene', 'al_gancho')
        ->assertJsonPath('data.entradas.iva_pct', '10.5')->assertJsonPath('data.entradas.al_gancho.rendimiento_pct', '53');
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $s->json('data.id'));
    expect(fn () => DB::transaction(fn () => DB::table('simulaciones')->update(['nombre' => 'x'])))->toThrow(Exception::class);

    $this->postJson("{$this->url}/calcular", ['entradas' => ['en_pie' => ['peso_bruto_kg' => '0', 'precio_kg' => '1.50', 'desbaste_pct' => '120'], 'comision_pct' => '-1', 'flete' => 100]])
        ->assertStatus(422)->assertJsonValidationErrors(['entradas.en_pie.peso_bruto_kg', 'entradas.en_pie.precio_kg', 'entradas.en_pie.desbaste_pct', 'entradas.comision_pct', 'entradas.flete']);
    $this->postJson("{$this->url}/calcular", ['entradas' => ['especie' => 'BUB']])->assertStatus(422)->assertJsonValidationErrors(['entradas.en_pie', 'entradas.al_gancho']);

    foreach (['encargado', 'asesor', 'lectura'] as $rol) {
        Sanctum::actingAs(miembro($this->est, $rol));
        $this->postJson("{$this->url}/calcular", ['entradas' => entradas30Bubillos()])->assertForbidden();
        $this->getJson($this->url)->assertForbidden();
    }
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->postJson("{$this->url}/calcular", ['entradas' => []])->assertNotFound();
});
