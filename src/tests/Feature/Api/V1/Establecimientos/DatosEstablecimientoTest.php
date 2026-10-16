<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}";
});

it('el dueño edita datos, zonas, instalaciones y ubicación; sube row_version y audita', function () {
    Sanctum::actingAs(duenoDe($this->est));

    $this->patchJson($this->url, [
        'row_version' => 1,
        'superficie_ha' => '3100',
        'historia' => 'Campo familiar desde 1952.',
        'instalaciones' => ['manga', 'balanza', 'revolcaderos'],
        'zonas_sanitarias' => ['rabia_endemica' => true, 'garrapata' => 'control'],
        'ubicacion' => ['lat' => '-29.18', 'lon' => '-58.07'],
        'renspa' => '18.105.0.00999/00',
    ])->assertOk()
        ->assertJsonPath('data.row_version', 2)
        ->assertJsonPath('data.superficie_ha', '3100')
        ->assertJsonPath('data.instalaciones', ['manga', 'balanza', 'revolcaderos'])
        ->assertJsonPath('data.zonas_sanitarias', ['aftosa_zona' => null, 'garrapata' => 'control', 'rabia_endemica' => true, 'fasciola_endemica' => false])
        ->assertJsonPath('data.ubicacion', ['lat' => '-29.18', 'lon' => '-58.07'])
        ->assertJsonPath('data.renspa', '18.105.0.00999/00');

    $audit = DB::table('auditoria')->where('entidad', 'establecimiento')->where('accion', 'editar')->sole();
    expect($audit->despues)->not->toContain('-29.18'); // la ubicación exacta no va a la bitácora
    expect(DB::table('cambios')->where('establecimiento_id', $this->est->id)->where('entidad', 'establecimiento')->count())->toBe(2);
});

it('PATCH parcial de zonas conserva las demás; sin cambios no sube row_version', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $this->patchJson($this->url, ['row_version' => 1, 'zonas_sanitarias' => ['fasciola_endemica' => true]])
        ->assertOk()->assertJsonPath('data.zonas_sanitarias.garrapata', 'erradicacion')
        ->assertJsonPath('data.zonas_sanitarias.fasciola_endemica', true);
    $this->patchJson($this->url, ['row_version' => 2, 'nombre' => 'La Esperanza'])->assertOk()->assertJsonPath('data.row_version', 2);
});

it('409 con row_version viejo', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $this->patchJson($this->url, ['row_version' => 1, 'nombre' => 'A'])->assertOk();
    $this->patchJson($this->url, ['row_version' => 1, 'nombre' => 'B'])->assertStatus(409)->assertJsonPath('code', 'conflicto_row_version');
});

it('422: row_version obligatorio, garrapata inválida, especies vacías, departamento de otra provincia', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $this->patchJson($this->url, ['nombre' => 'A'])->assertStatus(422)->assertJsonValidationErrors(['row_version']);
    $this->patchJson($this->url, ['row_version' => 1, 'zonas_sanitarias' => ['garrapata' => 'mucha'], 'especies' => []])
        ->assertStatus(422)->assertJsonValidationErrors(['zonas_sanitarias.garrapata', 'especies']);
    $this->patchJson($this->url, ['row_version' => 1, 'provincia_codigo' => '22'])->assertStatus(422)->assertJsonValidationErrors(['departamento_codigo']);
    $this->patchJson($this->url, ['row_version' => 1, 'departamento_codigo' => '22140'])->assertStatus(422)->assertJsonValidationErrors(['departamento_codigo']);
});

it('403 encargado/asesor/lectura; 404 otro establecimiento', function () {
    foreach (['encargado', 'asesor', 'lectura'] as $rol) {
        Sanctum::actingAs(miembro($this->est, $rol));
        $this->patchJson($this->url, ['row_version' => 1, 'nombre' => 'X'])->assertForbidden()->assertJsonPath('code', 'sin_permiso');
    }
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->patchJson($this->url, ['row_version' => 1, 'nombre' => 'X'])->assertNotFound();
});

it('el admin también puede editar', function () {
    Sanctum::actingAs(miembro($this->est, 'admin'));
    $this->patchJson($this->url, ['row_version' => 1, 'localidad' => 'Mercedes (zona rural)'])->assertOk();
});

it('guarda ficha extendida con visibilidad privada por defecto y filtra bloques públicos', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $this->patchJson($this->url, [
        'row_version' => 1,
        'ficha_ampliada' => [
            'sanidad' => ['brucelosis' => ['estado' => 'libre', 'vence' => '2027-04-01']],
            'clima' => ['precipitacion_media_mm' => 1350, 'fuente' => 'INTA', 'fecha' => '2026-10-16'],
            'personal' => ['equipo' => '7', 'encargado' => 'Nombre reservado'],
            'sanidad_renspa' => ['renspa' => '18.063.0.00421/00', 'renspa_vencimiento' => '2027-06-30'],
        ],
        'visibilidad_ficha' => ['clima' => true, 'personal' => true, 'sanidad_renspa' => true],
    ])->assertOk()->assertJsonPath('data.ficha_ampliada.sanidad.brucelosis.estado', 'libre')
        ->assertJsonPath('data.ficha_ampliada.clima.fuente', 'INTA');

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.ficha_ampliada.clima.fuente', 'INTA')
        ->assertJsonMissingPath('data.ficha_ampliada.sanidad')
        ->assertJsonPath('data.ficha_ampliada.personal.equipo', '7')
        ->assertJsonMissingPath('data.ficha_ampliada.personal.encargado')
        ->assertJsonMissingPath('data.ficha_ampliada.sanidad_renspa');
});

it('persiste el lindero como geometría PostGIS privada y lo expone como GeoJSON al dueño', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $geojson = ['type' => 'Polygon', 'coordinates' => [[[-58.1, -29.1], [-58.0, -29.1], [-58.0, -29.0], [-58.1, -29.1]]]];

    $this->patchJson($this->url, ['row_version' => 1, 'lindero_geojson' => $geojson])
        ->assertOk()->assertJsonPath('data.lindero_geojson.type', 'MultiPolygon');
    expect(DB::table('establecimientos')->where('id', $this->est->id)->value('lindero'))->not->toBeNull();

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.lindero_geojson', null);
});

it('422 si el GeoJSON del lindero no forma un polígono válido', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $this->patchJson($this->url, [
        'row_version' => 1,
        'lindero_geojson' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 1]]]],
    ])->assertStatus(422)->assertJsonValidationErrors(['lindero_geojson']);
});
