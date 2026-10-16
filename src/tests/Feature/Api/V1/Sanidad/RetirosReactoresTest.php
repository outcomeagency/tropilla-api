<?php

use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo('2026-10-01 15:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(duenoDe($this->est));

    $this->bufala = animalNuevo();
    $this->vaca = animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA']);
    $this->sana = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$this->bufala, $this->vaca, $this->sana], '2026-01-10'))->assertCreated();
    $this->lote = crearLote($this->est, 'BUB', true, 'Bucerros');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $this->lote, 'conteo' => ['BUB_BUCERRO' => 12]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-01-15'])->assertCreated();

    // Retiros: desparasitación individual (35 d), vacuna al lote anónimo (21 d) y una vieja ya vencida.
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.desparasitacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->bufala['animal_id']]],
        'payload' => ['producto_nombre' => 'Doramec', 'tipo' => 'interno', 'retiro_carne_dias' => 35], 'ocurrido_en' => '2026-09-20'])->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.vacunacion', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $this->lote, 'conteo' => ['BUB_BUCERRO' => 12]],
        'payload' => ['producto_nombre' => 'Carbunclo', 'enfermedad' => 'carbunclo', 'retiro_carne_dias' => 21], 'ocurrido_en' => '2026-09-25'])->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.desparasitacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->sana['animal_id']]],
        'payload' => ['producto_nombre' => 'Doramec', 'tipo' => 'interno', 'retiro_carne_dias' => 35], 'ocurrido_en' => '2026-06-01'])->assertCreated();
    // Reactor: la vaca.
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.prueba_diagnostica', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->vaca['animal_id']]],
        'payload' => ['prueba' => 'tuberculina_anocaudal', 'lectura_0h_mm' => '3', 'lectura_72h_mm' => '9'], 'ocurrido_en' => '2026-09-10', 'confirmar_advertencias' => true])->assertCreated();
});

it('listas destacadas: retiro vigente (individuales + lote anónimo) y reactores, por especie', function () {
    $r = $this->getJson("{$this->base}/sanidad/retiros")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->bufala['animal_id'])->assertJsonPath('data.0.retiro_vigente', true)
        ->assertJsonCount(1, 'lotes')->assertJsonPath('lotes.0.id', $this->lote)->assertJsonPath('lotes.0.retiro_carne_hasta', '2026-10-16')
        ->assertJsonPath('lotes.0.cabezas', 12);
    $this->getJson("{$this->base}/sanidad/retiros?especie=BOV")->assertJsonCount(0, 'data')->assertJsonCount(0, 'lotes');
    $this->getJson("{$this->base}/sanidad/reactores")->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->vaca['animal_id'])->assertJsonPath('data.0.reactor', 'tuberculina_anocaudal');
    $this->getJson("{$this->base}/sanidad/reactores?especie=BUB")->assertJsonCount(0, 'data');
    $this->getJson("{$this->base}/lotes/{$this->lote}")->assertJsonPath('data.retiro_carne_hasta', '2026-10-16');

    // Pasado el retiro, sale solo de la lista.
    $this->travelTo('2026-10-17 15:00:00');
    $this->getJson("{$this->base}/sanidad/retiros")->assertJsonCount(1, 'data')->assertJsonCount(0, 'lotes');
});

it('advierte al vender: reactor (no si va a faena) y retiro del lote anónimo; y al crear la oferta', function () {
    $venta = ['tipo' => 'hacienda.egreso', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->vaca['animal_id']]],
        'payload' => ['motivo' => 'venta', 'destino' => 'invernada', 'comprador' => 'Vecino'], 'ocurrido_en' => '2026-10-01'];
    $r = $this->postJson("{$this->base}/eventos", $venta)->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion');
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toBe(['reactor']);
    $this->postJson("{$this->base}/eventos", [...$venta, 'payload' => [...$venta['payload'], 'destino' => 'faena']])->assertCreated();

    $loteVenta = ['tipo' => 'hacienda.egreso', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $this->lote, 'conteo' => ['BUB_BUCERRO' => 4]],
        'payload' => ['motivo' => 'venta', 'destino' => 'invernada'], 'ocurrido_en' => '2026-10-01'];
    $r = $this->postJson("{$this->base}/eventos", $loteVenta)->assertStatus(422);
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toBe(['retiro_vigente']);

    $o = $this->postJson("{$this->base}/ofertas/desde-lote", ['tipo' => 'invernada', 'lote_id' => $this->lote])->assertCreated()->json('data');
    expect(collect($o['avisos'])->pluck('codigo')->all())->toContain('retiro_vigente');
});

it('422 de filtro; lectura ve; 404 ajeno (aunque el filtro sea inválido)', function () {
    $this->getJson("{$this->base}/sanidad/retiros?especie=XXX")->assertStatus(422);
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson("{$this->base}/sanidad/reactores")->assertOk()->assertJsonCount(1, 'data');
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/sanidad/retiros?especie=XXX")->assertNotFound();
    $this->getJson("{$this->base}/sanidad/reactores")->assertNotFound();
});
