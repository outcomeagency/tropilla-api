<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/potreros";
});

function poligonoHa(): array
{
    // ~ 1 km x 1 km cerca de Mercedes (≈ 100 ha)
    return ['type' => 'Polygon', 'coordinates' => [[[-58.08, -29.18], [-58.0697, -29.18], [-58.0697, -29.1710], [-58.08, -29.1710], [-58.08, -29.18]]]];
}

it('el encargado crea un potrero con id del cliente (idempotente) y aparece en la lista', function () {
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $id = (string) Str::uuid7();
    $body = ['id' => $id, 'nombre' => 'El Bajo', 'superficie_ha' => '120.5', 'ambiente' => 'bajo', 'recurso_forrajero' => 'campo_natural', 'agua' => true, 'revolcadero' => true];

    $this->postJson($this->url, $body)->assertCreated()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.superficie_ha', '120.5')
        ->assertJsonPath('data.ocupacion', ['cabezas' => 0, 'lotes' => []])->assertJsonPath('data.row_version', 1);
    $this->postJson($this->url, $body)->assertOk()->assertJsonPath('data.id', $id); // reintento

    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'El Bajo');
    expect(DB::table('cambios')->where('entidad', 'potrero')->count())->toBe(1);
});

it('polígono GeoJSON (PostGIS): se guarda, se devuelve y calcula la superficie si falta', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $res = $this->postJson($this->url, ['nombre' => 'La Loma', 'poligono' => poligonoHa()])->assertCreated();

    expect($res->json('data.poligono.type'))->toBe('Polygon')
        ->and((float) $res->json('data.superficie_ha'))->toBeGreaterThan(90)->toBeLessThan(110);
});

it('edita con row_version, 409 si está viejo; archiva con motivo y deja de listarse', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $id = $this->postJson($this->url, ['nombre' => 'Potrero 1'])->json('data.id');

    $this->patchJson("{$this->url}/{$id}", ['row_version' => 1, 'nombre' => 'Potrero Norte', 'ambiente' => 'loma'])
        ->assertOk()->assertJsonPath('data.nombre', 'Potrero Norte')->assertJsonPath('data.row_version', 2);
    $this->patchJson("{$this->url}/{$id}", ['row_version' => 1, 'notas' => 'x'])->assertStatus(409);

    $this->postJson("{$this->url}/{$id}/archivar", ['motivo' => 'Se unificó con el 2'])->assertOk()
        ->assertJsonPath('data.archivado_en', fn ($v) => $v !== null);
    $this->getJson($this->url)->assertJsonCount(0, 'data');
    $this->getJson("{$this->url}?archivados=1")->assertJsonCount(1, 'data');
    // el nombre queda libre al archivar
    $this->postJson($this->url, ['nombre' => 'potrero norte'])->assertCreated();
});

it('422: nombre duplicado (case-insensitive), ambiente inválido, polígono abierto, sin row_version', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $id = $this->postJson($this->url, ['nombre' => 'El Bajo'])->json('data.id');

    $this->postJson($this->url, ['nombre' => 'el bajo'])->assertStatus(422)->assertJsonValidationErrors(['nombre']);
    $this->postJson($this->url, ['nombre' => 'X', 'ambiente' => 'desierto'])->assertStatus(422)->assertJsonValidationErrors(['ambiente']);
    $p = poligonoHa();
    array_pop($p['coordinates'][0]);
    $this->postJson($this->url, ['nombre' => 'Y', 'poligono' => $p])->assertStatus(422)->assertJsonValidationErrors(['poligono']);
    $this->patchJson("{$this->url}/{$id}", ['nombre' => 'Z'])->assertStatus(422)->assertJsonValidationErrors(['row_version']);
});

it('lectura ve pero no crea (403); otro establecimiento 404, también para el potrero', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $id = $this->postJson($this->url, ['nombre' => 'El Bajo'])->json('data.id');

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson("{$this->url}/{$id}")->assertOk();
    $this->postJson($this->url, ['nombre' => 'Otro'])->assertForbidden()->assertJsonPath('code', 'sin_permiso');

    $otro = crearEstablecimiento();
    Sanctum::actingAs(duenoDe($otro));
    $this->getJson($this->url)->assertNotFound();
    $this->getJson("/api/v1/establecimientos/{$otro->id}/potreros/{$id}")->assertNotFound();
});
