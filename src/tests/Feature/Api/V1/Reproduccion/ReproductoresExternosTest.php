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
    $this->getJson("/api/v1/sync/snapshot?establecimiento_id={$this->est->id}")->assertOk()->assertJsonPath('data.reproductores_externos.0.id', $id);
    $cambios = $this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}")->assertOk()->json('data.cambios');
    expect(collect($cambios)->firstWhere('entidad', 'reproductor_externo')['datos']['id'])->toBe($id);
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

it('acepta progenitores externos tipados en el alta del animal y los devuelve en genealogía', function () {
    $madre = $this->postJson($this->url, ['nombre' => 'Madre de campo vecino', 'especie_codigo' => 'BUB'])->assertCreated()->json('data.id');
    $padre = $this->postJson($this->url, ['nombre' => 'Padre de cabaña', 'especie_codigo' => 'BUB'])->assertCreated()->json('data.id');
    $cria = animalNuevo(['madre_externa_id' => $madre, 'padre_externo_id' => $padre]);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/eventos", altaBody([$cria]))->assertCreated();
    $this->getJson("/api/v1/establecimientos/{$this->est->id}/animales/{$cria['animal_id']}/genealogia")
        ->assertOk()->assertJsonPath('data.pedigri.madre.tipo', 'externo')->assertJsonPath('data.pedigri.madre.reproductor.id', $madre)
        ->assertJsonPath('data.pedigri.padre.tipo', 'externo')->assertJsonPath('data.pedigri.padre.reproductor.id', $padre);
});

it('archiva sin borrar el historial, oculta del catálogo y publica el cambio offline', function () {
    $id = $this->postJson($this->url, ['nombre' => 'Toro a archivar', 'especie_codigo' => 'BOV'])
        ->assertCreated()->json('data.id');

    $this->deleteJson("{$this->url}/{$id}")->assertNoContent();
    $this->getJson($this->url)->assertOk()->assertJsonCount(0, 'data');
    expect(DB::table('reproductor_externo_versiones')->where('reproductor_id', $id)->pluck('accion')->all())
        ->toBe(['alta', 'archivo']);
    $cambios = $this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}")->assertOk()->json('data.cambios');
    expect(collect($cambios)->first(fn ($c) => $c['entidad'] === 'reproductor_externo' && $c['id'] === $id)['op'])
        ->toBe('archivar');
});
