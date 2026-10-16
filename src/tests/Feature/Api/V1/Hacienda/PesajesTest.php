<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/eventos";
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->lote = crearLote($this->est, 'BUB', false, 'Bubillos');
    $this->a = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUBILLO', 'castrado' => true, 'lote_id' => $this->lote]);
    $this->b = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUBILLO', 'castrado' => true, 'lote_id' => $this->lote]);
    $this->postJson($this->url, altaBody([$this->a, $this->b], '2026-01-01'))->assertCreated();
    $this->pesar = fn (array $a, string $kg, string $fecha, array $extra = []) => $this->postJson($this->url, ['tipo' => 'hacienda.pesaje',
        'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]], 'payload' => ['modo' => 'individual', 'peso_kg' => $kg, 'metodo' => 'balanza'], 'ocurrido_en' => $fecha, ...$extra]);
});

it('GDP individual entre pesajes y GDP del lote = promedio de las individuales', function () {
    ($this->pesar)($this->a, '400', '2026-06-01')->assertCreated();
    ($this->pesar)($this->a, '440', '2026-07-01')->assertCreated(); // 40 kg / 30 d
    ($this->pesar)($this->b, '380', '2026-06-01')->assertCreated();
    ($this->pesar)($this->b, '398', '2026-07-01')->assertCreated(); // 18 kg / 30 d

    $this->getJson("/api/v1/establecimientos/{$this->est->id}/animales/{$this->a['animal_id']}")->assertJsonPath('data.gdp_kg_dia', '1.333');
    $this->getJson("/api/v1/establecimientos/{$this->est->id}/lotes/{$this->lote}")->assertOk()
        ->assertJsonPath('data.peso_promedio_kg', '419')->assertJsonPath('data.peso_promedio_fecha', '2026-07-01')
        ->assertJsonPath('data.gdp_promedio_kg_dia', '0.967');
});

it('pesaje promedio de lote con cantidad: no pisa el peso individual', function () {
    ($this->pesar)($this->a, '400', '2026-06-01')->assertCreated();
    $ids = [$this->a['animal_id'], $this->b['animal_id']];
    sort($ids);
    $this->postJson($this->url, ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => $ids, 'lote_id' => $this->lote],
        'payload' => ['modo' => 'promedio', 'peso_kg' => '430', 'cantidad' => 2, 'metodo' => 'balanza'], 'ocurrido_en' => '2026-08-01'])->assertCreated();

    expect(DB::table('animales_estado')->where('animal_id', $this->a['animal_id'])->value('peso_kg'))->toBe('400.0');
    $this->getJson("/api/v1/establecimientos/{$this->est->id}/lotes/{$this->lote}")->assertJsonPath('data.peso_promedio_kg', '430');
    $this->postJson($this->url, ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => $ids],
        'payload' => ['modo' => 'promedio', 'peso_kg' => '430', 'metodo' => 'balanza'], 'ocurrido_en' => '2026-08-01'])->assertStatus(422); // sin cantidad
});

it('alerta si el pesaje difiere > 30 % en < 30 días (parámetro por especie); no alerta pasado el plazo', function () {
    ($this->pesar)($this->a, '400', '2026-06-01')->assertCreated();
    ($this->pesar)($this->a, '530', '2026-06-20')->assertStatus(422)->assertJsonPath('advertencias.0.codigo', 'pesaje_atipico');
    ($this->pesar)($this->a, '530', '2026-07-15')->assertCreated();
});
