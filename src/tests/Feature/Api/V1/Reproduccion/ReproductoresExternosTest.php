<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->url = "/api/v1/establecimientos/{$this->est->id}/reproductores-externos";
});

it('registra y corrige reproductores externos con historial append-only y control de versión', function () {
    $body = ['nombre' => 'Toro CI', 'rp' => 'RP-77', 'registro_genealogico' => 'HBA 123', 'raza' => 'BOV_ANGUS', 'especie_codigo' => 'BOV', 'centro' => 'Centro Norte'];
    $id = $this->postJson($this->url, $body)->assertCreated()->json('data.id');
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.id', $id);
    $this->patchJson("{$this->url}/{$id}", [...$body, 'nombre' => 'Toro CI 2', 'row_version' => 1])->assertOk()->assertJsonPath('data.nombre', 'Toro CI 2');
    expect(DB::table('reproductor_externo_versiones')->where('reproductor_id', $id)->count())->toBe(2);
    $this->patchJson("{$this->url}/{$id}", [...$body, 'row_version' => 1])->assertStatus(409);
});

it('no expone reproductores de otro establecimiento', function () {
    $otro = crearEstablecimiento();
    $this->getJson("/api/v1/establecimientos/{$otro->id}/reproductores-externos")->assertNotFound();
});

it('permite encadenar reproductores externos como progenitores', function () {
    $madre = $this->postJson($this->url, ['nombre' => 'Madre externa', 'especie_codigo' => 'BOV'])->assertCreated()->json('data.id');
    $this->postJson($this->url, ['nombre' => 'Hijo comprado', 'especie_codigo' => 'BOV', 'madre_externa_id' => $madre])
        ->assertCreated()->assertJsonPath('data.madre_externa_id', $madre);
});
