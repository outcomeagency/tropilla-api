<?php

use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->url = "/api/v1/establecimientos/{$this->est->id}/animales";
    Sanctum::actingAs(miembro($this->est, 'encargado'));
});

it('alta rápida: especie → sexo → identificador → categoría; devuelve la ficha (201)', function () {
    $res = $this->postJson($this->url, ['animal' => [
        'especie' => 'BUB', 'sexo' => 'H', 'categoria' => 'BUB_BUBILLA', 'raza' => 'BUB_MURRAH',
        'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '0500']], 'fecha_nacimiento' => null,
    ]])->assertCreated()
        ->assertJsonPath('data.especie', 'BUB')->assertJsonPath('data.categoria', 'BUB_BUBILLA')
        ->assertJsonPath('data.identificacion_principal', '0500')->assertJsonPath('data.estado', 'activo')
        ->assertJsonPath('data.identificaciones.0.vigente', true)->assertJsonPath('data.origen', 'inventario')
        ->assertJsonPath('data.confianza_resumen.eventos', 1);

    $this->getJson("/api/v1/establecimientos/{$this->est->id}/eventos")->assertJsonPath('data.0.tipo', 'hacienda.alta');
    $this->getJson("{$this->url}/identificador?valor=0500")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $res->json('data.id'));
    $this->getJson("{$this->url}/identificador?valor=05-00&tipo=caravana_visual")->assertJsonCount(1, 'data'); // normaliza guiones
    $this->getJson("{$this->url}/identificador?valor=9999")->assertJsonCount(0, 'data');
});

it('con id de animal del cliente; 409 si la identificación ya está vigente en otro', function () {
    $id = (string) Str::uuid7();
    $this->postJson($this->url, ['animal' => ['animal_id' => $id, 'especie' => 'BOV', 'sexo' => 'H', 'categoria' => 'BOV_VACA',
        'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '1187']]]])->assertCreated()->assertJsonPath('data.id', $id);

    $this->postJson($this->url, ['animal' => ['especie' => 'BOV', 'sexo' => 'H', 'categoria' => 'BOV_VACA',
        'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => '1187']]]])->assertStatus(409)->assertJsonPath('code', 'identificacion_duplicada');
});

it('422: falta especie/sexo/categoría, categoría de otra especie, RFID que no tiene 15 dígitos', function () {
    $this->postJson($this->url, ['animal' => []])->assertStatus(422)->assertJsonValidationErrors(['animal.especie', 'animal.sexo', 'animal.categoria']);
    $this->postJson($this->url, ['animal' => ['especie' => 'BUB', 'sexo' => 'H', 'categoria' => 'BOV_VACA']])
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson($this->url, ['animal' => ['especie' => 'BUB', 'sexo' => 'H', 'categoria' => 'BUB_BUFALA', 'identificaciones' => [['tipo' => 'rfid', 'valor' => '123']]]])
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
});

it('403 lectura; 404 otro establecimiento; ficha 404 de animal ajeno', function () {
    $id = $this->postJson($this->url, ['animal' => ['especie' => 'BUB', 'sexo' => 'M', 'categoria' => 'BUB_BUCERRO']])->json('data.id');

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson($this->url, ['animal' => ['especie' => 'BUB', 'sexo' => 'M', 'categoria' => 'BUB_BUCERRO']])->assertForbidden();
    $this->getJson("{$this->url}/{$id}")->assertOk();

    $otro = crearEstablecimiento();
    Sanctum::actingAs(duenoDe($otro));
    $this->postJson($this->url, ['animal' => ['especie' => 'BUB', 'sexo' => 'M', 'categoria' => 'BUB_BUCERRO']])->assertNotFound();
    $this->getJson("/api/v1/establecimientos/{$otro->id}/animales/{$id}")->assertNotFound();
});
