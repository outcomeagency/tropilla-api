<?php

use App\Domain\Establecimientos\Models\Membresia;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->ev = fn (string $tipo, array $sujetos, array $payload, string $fecha) => $this->postJson("{$this->base}/eventos",
        ['tipo' => $tipo, 'sujetos' => $sujetos, 'payload' => $payload, 'ocurrido_en' => $fecha])->assertCreated();
});

it('serie de pesos: al nacer, pesajes, destete y promedio, con la GDP de la ficha', function () {
    $madre = animalNuevo(['raza' => 'BUB_MURRAH']);
    $this->postJson("{$this->base}/eventos", altaBody([$madre], '2025-01-01'))->assertCreated();
    $cria = animalNuevo(['sexo' => 'H', 'categoria' => 'BUB_BUCERRA']);
    $yo = ['tipo' => 'animales', 'animal_ids' => [$cria['animal_id']]];
    ($this->ev)('reproduccion.parto', ['tipo' => 'animales', 'animal_ids' => [$madre['animal_id']]],
        ['tipo' => 'normal', 'crias' => [['viva' => true, 'animal' => $cria, 'peso_kg' => '38']]], '2026-01-10');
    ($this->ev)('hacienda.pesaje', $yo, ['modo' => 'individual', 'peso_kg' => '98', 'metodo' => 'balanza'], '2026-03-11');
    ($this->ev)('reproduccion.destete', $yo, ['tipo' => 'tradicional', 'peso_kg' => '150'], '2026-05-10');
    ($this->ev)('hacienda.pesaje', $yo, ['modo' => 'individual', 'peso_kg' => '200', 'metodo' => 'balanza'], '2026-07-09');
    ($this->ev)('hacienda.pesaje', $yo, ['modo' => 'promedio', 'peso_kg' => '210.5', 'cantidad' => 1, 'metodo' => 'cinta'], '2026-08-01');

    $serie = $this->getJson("{$this->base}/animales/{$cria['animal_id']}/pesajes")->assertOk()->json('data');
    expect(array_column($serie, 'fecha'))->toBe(['2026-01-10', '2026-03-11', '2026-05-10', '2026-07-09', '2026-08-01'])
        ->and(array_column($serie, 'peso_kg'))->toBe(['38', '98', '150', '200', '210.5'])
        ->and(array_column($serie, 'fuente'))->toBe(['nacimiento', 'pesaje', 'destete', 'pesaje', 'pesaje'])
        ->and(array_column($serie, 'modo'))->toBe(['individual', 'individual', 'individual', 'individual', 'promedio'])
        ->and(array_column($serie, 'gdp_kg_dia'))->toBe([null, '1', null, '0.85', null])
        ->and($serie[4])->toMatchArray(['metodo' => 'cinta', 'cantidad' => 1, 'heredado_de_lote' => false]);
    $this->getJson("{$this->base}/animales/{$cria['animal_id']}")->assertJsonPath('data.gdp_kg_dia', '0.85');

    // La madre no hereda el peso al nacer de su cría.
    $this->getJson("{$this->base}/animales/{$madre['animal_id']}/pesajes")->assertOk()->assertJsonCount(0, 'data');

    // Filtro por fechas: la GDP del primer punto visible usa el pesaje anterior.
    $this->getJson("{$this->base}/animales/{$cria['animal_id']}/pesajes?desde=2026-03-01&hasta=2026-07-09")->assertOk()
        ->assertJsonCount(3, 'data')->assertJsonPath('data.0.gdp_kg_dia', '1');
});

it('individualizado desde un lote anónimo: hereda los promedios del lote previos (excluibles)', function () {
    $lote = crearLote($this->est, 'BUB', true, 'Bucerros');
    $sl = ['tipo' => 'lote', 'lote_id' => $lote];
    ($this->ev)('hacienda.alta', [...$sl, 'conteo' => ['BUB_BUCERRA' => 10]], ['motivo' => 'inventario_inicial'], '2026-02-01');
    ($this->ev)('hacienda.pesaje', $sl, ['modo' => 'promedio', 'peso_kg' => '95', 'cantidad' => 10, 'metodo' => 'cinta'], '2026-03-01');
    $nueva = animalNuevo(['categoria' => 'BUB_BUCERRA', 'lote_id' => $lote]);
    ($this->ev)('hacienda.individualizacion', [...$sl, 'conteo' => ['BUB_BUCERRA' => 1]], ['animales' => [$nueva]], '2026-04-10');
    ($this->ev)('hacienda.pesaje', $sl, ['modo' => 'promedio', 'peso_kg' => '140', 'cantidad' => 9, 'metodo' => 'cinta'], '2026-05-01'); // posterior: no
    ($this->ev)('hacienda.pesaje', ['tipo' => 'animales', 'animal_ids' => [$nueva['animal_id']]], ['modo' => 'individual', 'peso_kg' => '130', 'metodo' => 'balanza'], '2026-05-01');

    $url = "{$this->base}/animales/{$nueva['animal_id']}/pesajes";
    $this->getJson($url)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.heredado_de_lote', true)->assertJsonPath('data.0.modo', 'promedio')->assertJsonPath('data.0.lote_id', $lote)
        ->assertJsonPath('data.1.heredado_de_lote', false)->assertJsonPath('data.1.gdp_kg_dia', null);
    $this->getJson("{$url}?incluir_lote=false")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.peso_kg', '130');
});

it('422 filtros inválidos; 404 animal inexistente u otro establecimiento; 403 membresía revocada', function () {
    $a = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$a]))->assertCreated();
    $url = "{$this->base}/animales/{$a['animal_id']}/pesajes";

    $this->getJson("{$url}?desde=01/02/2026")->assertStatus(422)->assertJsonValidationErrors('desde');
    $this->getJson("{$url}?desde=2026-05-01&hasta=2026-04-01")->assertStatus(422)->assertJsonValidationErrors('hasta');
    $this->getJson("{$this->base}/animales/".animalNuevo()['animal_id'].'/pesajes')->assertNotFound();

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson($url)->assertOk();

    $ex = miembro($this->est, 'asesor');
    Membresia::query()->where('usuario_id', $ex->id)->update(['estado' => 'revocada', 'revocada_en' => now()]);
    Sanctum::actingAs($ex);
    $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'membresia_revocada');

    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$url}?desde=basura")->assertNotFound();
});
