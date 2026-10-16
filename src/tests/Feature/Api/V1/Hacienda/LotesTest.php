<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(miembro($this->est, 'encargado'));
});

it('crea lote anónimo, recibe eventos grupales con conteo, potrero y peso promedio; individualizar hereda historia', function () {
    $lote = $this->postJson("{$this->base}/lotes", ['nombre' => 'Bucerros 2026', 'especie' => 'BUB', 'proposito' => 'recria', 'anonimo' => true])
        ->assertCreated()->assertJsonPath('data.anonimo', true)->assertJsonPath('data.cabezas_anonimas', 0)->json('data.id');
    $potrero = crearPotrero($this->est);
    $ev = fn (string $tipo, array $sujetos, array $payload, string $fecha) => $this->postJson("{$this->base}/eventos",
        ['tipo' => $tipo, 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, ...$sujetos], 'payload' => $payload, 'ocurrido_en' => $fecha])->assertCreated();

    $ev('hacienda.alta', ['conteo' => ['BUB_BUCERRO' => 20, 'BUB_BUCERRA' => 18]], ['motivo' => 'inventario_inicial'], '2026-02-01');
    $ev('hacienda.traslado', [], ['potrero_destino_id' => $potrero], '2026-02-02');
    $ev('hacienda.pesaje', [], ['modo' => 'promedio', 'peso_kg' => '95', 'cantidad' => 38, 'metodo' => 'cinta'], '2026-03-01');
    $ev('hacienda.pesaje', [], ['modo' => 'promedio', 'peso_kg' => '125', 'cantidad' => 38, 'metodo' => 'cinta'], '2026-03-31');
    $ev('hacienda.muerte', ['conteo' => ['BUB_BUCERRO' => 1]], ['causa' => 'predador'], '2026-04-01');
    $nueva = animalNuevo(['categoria' => 'BUB_BUCERRA', 'lote_id' => $lote]);
    $ev('hacienda.individualizacion', ['conteo' => ['BUB_BUCERRA' => 1]], ['animales' => [$nueva]], '2026-04-10');

    $this->getJson("{$this->base}/lotes/{$lote}")->assertOk()
        ->assertJsonPath('data.conteo', ['BUB_BUCERRA' => 17, 'BUB_BUCERRO' => 19])
        ->assertJsonPath('data.cabezas_anonimas', 36)->assertJsonPath('data.cabezas_individuales', 1)
        ->assertJsonPath('data.potrero.id', $potrero)->assertJsonPath('data.peso_promedio_kg', '125')
        ->assertJsonPath('data.gdp_promedio_kg_dia', '1');
    $this->getJson("{$this->base}/lotes/{$lote}/eventos")->assertOk()->assertJsonCount(6, 'data');
    $this->getJson("{$this->base}/animales/{$nueva['animal_id']}/eventos")->assertJsonPath('data.1.heredado_de_lote', true);
});

it('un lote no mezcla especies: alta de otra especie y cambio de lote se bloquean', function () {
    $lote = $this->postJson("{$this->base}/lotes", ['nombre' => 'Vacas', 'especie' => 'BOV', 'anonimo' => false])->json('data.id');
    $this->postJson("{$this->base}/eventos", altaBody([animalNuevo(['lote_id' => $lote])]))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUFALA' => 3]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-01-01'])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
});

it('lista por especie, edita con row_version (especie inmutable) y archiva solo sin cabezas', function () {
    $id = $this->postJson("{$this->base}/lotes", ['nombre' => 'Recría', 'especie' => 'BUB'])->json('data.id');
    $this->postJson("{$this->base}/lotes", ['nombre' => 'Toros', 'especie' => 'BOV']);
    $this->getJson("{$this->base}/lotes?especie=BUB")->assertJsonCount(1, 'data');

    $this->patchJson("{$this->base}/lotes/{$id}", ['row_version' => 1, 'nombre' => 'Recría 2026'])->assertOk()->assertJsonPath('data.row_version', 2);
    $this->patchJson("{$this->base}/lotes/{$id}", ['row_version' => 1, 'nombre' => 'X'])->assertStatus(409);
    $this->patchJson("{$this->base}/lotes/{$id}", ['row_version' => 2, 'especie' => 'BOV'])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');

    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $id, 'conteo' => ['BUB_BUBILLA' => 2]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-01-01'])->assertCreated();
    $this->postJson("{$this->base}/lotes/{$id}/archivar", ['motivo' => 'Se vendió'])->assertStatus(422);
    $vacio = $this->postJson("{$this->base}/lotes", ['nombre' => 'Vacío', 'especie' => 'BUB'])->json('data.id');
    $this->postJson("{$this->base}/lotes/{$vacio}/archivar", ['motivo' => 'No se usa'])->assertOk();
    $this->getJson("{$this->base}/lotes")->assertJsonCount(2, 'data');
});

it('422 datos inválidos, id del cliente idempotente; 403 lectura; 404 otro establecimiento', function () {
    $this->postJson("{$this->base}/lotes", ['nombre' => 'X', 'especie' => 'OVI'])->assertStatus(422)->assertJsonValidationErrors(['especie']);
    $id = (string) Str::uuid7();
    $this->postJson("{$this->base}/lotes", ['id' => $id, 'nombre' => 'A', 'especie' => 'BUB'])->assertCreated();
    $this->postJson("{$this->base}/lotes", ['id' => $id, 'nombre' => 'A', 'especie' => 'BUB'])->assertOk();

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson("{$this->base}/lotes", ['nombre' => 'X', 'especie' => 'BUB'])->assertForbidden();
    $this->getJson("{$this->base}/lotes/{$id}")->assertOk();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/lotes")->assertNotFound();
    expect(DB::table('lotes')->count())->toBe(1);
});
