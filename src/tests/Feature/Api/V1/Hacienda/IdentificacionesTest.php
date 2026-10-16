<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/eventos";
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->a = animalNuevo(['raza' => 'BUB_MURRAH', 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '0142']]]);
    $this->postJson($this->url, altaBody([$this->a]))->assertCreated();
    $this->ident = fn (array $payload, array $extra = []) => $this->postJson($this->url, [
        'tipo' => 'hacienda.identificacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->a['animal_id']]],
        'payload' => $payload, 'ocurrido_en' => '2026-05-01', ...$extra,
    ]);
});

function idents(string $animalId): array
{
    return DB::table('animal_identificaciones')->where('animal_id', $animalId)->orderBy('id')
        ->get(['tipo', 'valor', 'vigente', 'retirada_en'])->map(fn ($r) => (array) $r)->all();
}

it('todos los tipos: caravana, oficial, RFID (15 dígitos), tatuaje con prefijo, marca, registro con entidad', function () {
    ($this->ident)(['accion' => 'colocacion', 'identificacion' => ['tipo' => 'caravana_oficial', 'valor' => 'AR-18-0001']])->assertCreated();
    ($this->ident)(['accion' => 'colocacion', 'identificacion' => ['tipo' => 'rfid', 'valor' => '032 000123456789']])->assertCreated();
    ($this->ident)(['accion' => 'colocacion', 'identificacion' => ['tipo' => 'tatuaje', 'valor' => 'U-0142']])->assertCreated();
    ($this->ident)(['accion' => 'colocacion', 'identificacion' => ['tipo' => 'marca_senal', 'valor' => 'DR']])->assertCreated();
    ($this->ident)(['accion' => 'colocacion', 'identificacion' => ['tipo' => 'registro_genealogico', 'valor' => 'MU-1234', 'entidad' => 'AACB', 'tipo_registro' => 'P1']])->assertCreated();

    $ficha = $this->getJson("/api/v1/establecimientos/{$this->est->id}/animales/{$this->a['animal_id']}")->assertOk()->json('data.identificaciones');
    expect(collect($ficha)->pluck('tipo')->sort()->values()->all())->toBe(['caravana_oficial', 'caravana_visual', 'marca_senal', 'registro_genealogico', 'rfid', 'tatuaje'])
        ->and(collect($ficha)->firstWhere('tipo', 'registro_genealogico'))->toMatchArray(['entidad' => 'AACB', 'tipo_registro' => 'P1']);
});

it('tatuaje sin el prefijo de la raza advierte; RFID de 12 dígitos y registro sin entidad no se aceptan', function () {
    ($this->ident)(['accion' => 'colocacion', 'identificacion' => ['tipo' => 'tatuaje', 'valor' => 'E-0142']])
        ->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion')->assertJsonPath('advertencias.0.codigo', 'tatuaje_prefijo');
    ($this->ident)(['accion' => 'colocacion', 'identificacion' => ['tipo' => 'rfid', 'valor' => '000123456789']])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    ($this->ident)(['accion' => 'colocacion', 'identificacion' => ['tipo' => 'registro_genealogico', 'valor' => 'X']])->assertStatus(422);
});

it('reposición conserva el historial: la anterior queda no vigente y la nueva vigente', function () {
    ($this->ident)(['accion' => 'reposicion', 'identificacion' => ['tipo' => 'caravana_visual', 'valor' => '0142B'], 'reemplaza' => ['tipo' => 'caravana_visual', 'valor' => '0142']])->assertCreated();

    expect(idents($this->a['animal_id']))->toBe([
        ['tipo' => 'caravana_visual', 'valor' => '0142', 'vigente' => false, 'retirada_en' => '2026-05-01'],
        ['tipo' => 'caravana_visual', 'valor' => '0142B', 'vigente' => true, 'retirada_en' => null],
    ]);
    // la caravana vieja queda libre para otro animal
    $this->postJson($this->url, altaBody([animalNuevo(['identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '0142']]])]))->assertCreated();
});

it('reposición sin `reemplaza` o de una identificación que no tiene → 422; retiro deja el historial', function () {
    ($this->ident)(['accion' => 'reposicion', 'identificacion' => ['tipo' => 'caravana_visual', 'valor' => '9']])->assertStatus(422);
    ($this->ident)(['accion' => 'reposicion', 'identificacion' => ['tipo' => 'caravana_visual', 'valor' => '9'], 'reemplaza' => ['tipo' => 'caravana_visual', 'valor' => '7']])
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    ($this->ident)(['accion' => 'retiro', 'identificacion' => ['tipo' => 'caravana_visual', 'valor' => '0142']])->assertCreated();
    expect(idents($this->a['animal_id']))->toHaveCount(1)->and(idents($this->a['animal_id'])[0]['vigente'])->toBeFalse();
});

it('única vigente por tipo dentro del establecimiento (409 online)', function () {
    $b = animalNuevo();
    $this->postJson($this->url, altaBody([$b]))->assertCreated();
    $this->postJson($this->url, ['tipo' => 'hacienda.identificacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$b['animal_id']]],
        'payload' => ['accion' => 'colocacion', 'identificacion' => ['tipo' => 'caravana_visual', 'valor' => '01-42']], 'ocurrido_en' => '2026-05-01'])
        ->assertStatus(409)->assertJsonPath('code', 'identificacion_duplicada');
});

it('lectura no identifica (403); otro establecimiento 404', function () {
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    ($this->ident)(['accion' => 'colocacion', 'identificacion' => ['tipo' => 'marca_senal', 'valor' => 'DR']])->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    ($this->ident)(['accion' => 'colocacion', 'identificacion' => ['tipo' => 'marca_senal', 'valor' => 'DR']])->assertNotFound();
});
