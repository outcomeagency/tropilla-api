<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

it('individualizar desde un lote anónimo con retiro vigente: el animal lo hereda (y no hereda lo posterior)', function () {
    $ind = function (string $fecha) {
        $nuevo = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUCERRO', 'lote_id' => $this->lote]);
        $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.individualizacion', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $this->lote, 'conteo' => ['BUB_BUCERRO' => 1]],
            'payload' => ['animales' => [$nuevo]], 'ocurrido_en' => $fecha])->assertCreated();

        return $nuevo['animal_id'];
    };
    $id = $ind('2026-10-01');
    $this->getJson("{$this->base}/animales/{$id}")->assertJsonPath('data.retiro_vigente', true)->assertJsonPath('data.retiro_carne_hasta', '2026-10-16');
    $this->getJson("{$this->base}/sanidad/retiros")->assertJsonCount(2, 'data');
    $venta = ['tipo' => 'hacienda.egreso', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$id]], 'payload' => ['motivo' => 'venta', 'destino' => 'invernada'], 'ocurrido_en' => '2026-10-01'];
    expect(collect($this->postJson("{$this->base}/eventos", $venta)->assertStatus(422)->json('advertencias'))->pluck('codigo')->all())->toBe(['retiro_vigente']);

    // Una individualización anterior a la vacuna del lote no la hereda; la reconstrucción da lo mismo.
    $antes = $ind('2026-09-01');
    $this->getJson("{$this->base}/animales/{$antes}")->assertJsonPath('data.retiro_vigente', false)->assertJsonPath('data.retiro_carne_hasta', null);
    $this->artisan('proyecciones:reconstruir', ['establecimiento' => $this->est->id])->assertSuccessful();
    $this->getJson("{$this->base}/animales/{$id}")->assertJsonPath('data.retiro_carne_hasta', '2026-10-16');

    // Vencido el retiro del lote, el individualizado tampoco lo tiene vigente.
    $this->travelTo('2026-10-17 15:00:00');
    $this->getJson("{$this->base}/animales/{$id}")->assertJsonPath('data.retiro_vigente', false);
});

it('aplicación a un potrero alcanza a los lotes anónimos presentes (retiro, timeline, herencia); 403 rol; 404 ajeno; 422', function () {
    $potrero = crearPotrero($this->est, 'La Loma');
    $enPotrero = crearLote($this->est, 'BUB', true, 'Bubillas');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $enPotrero, 'conteo' => ['BUB_BUBILLA' => 8]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-02-01'])->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.traslado', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $enPotrero],
        'payload' => ['potrero_destino_id' => $potrero], 'ocurrido_en' => '2026-02-02'])->assertCreated();
    $vacuna = ['tipo' => 'sanidad.vacunacion', 'sujetos' => ['tipo' => 'potrero', 'potrero_id' => $potrero],
        'payload' => ['producto_nombre' => 'Clostridiales', 'enfermedad' => 'carbunclo', 'retiro_carne_dias' => 30], 'ocurrido_en' => '2026-10-01'];

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson("{$this->base}/eventos", $vacuna)->assertForbidden();
    Sanctum::actingAs(duenoDe($this->est));
    $this->postJson("{$this->base}/eventos", [...$vacuna, 'payload' => [...$vacuna['payload'], 'retiro_carne_dias' => '30']])->assertStatus(422);

    $ev = $this->postJson("{$this->base}/eventos", $vacuna)->assertCreated()->json('data.id');
    expect(DB::table('evento_lotes')->where('evento_id', $ev)->pluck('lote_id')->all())->toBe([$enPotrero]);
    $this->getJson("{$this->base}/lotes/{$enPotrero}")->assertJsonPath('data.retiro_carne_hasta', '2026-10-31');
    $this->getJson("{$this->base}/lotes/{$this->lote}")->assertJsonPath('data.retiro_carne_hasta', '2026-10-16'); // otro potrero: sin cambios
    $this->getJson("{$this->base}/lotes/{$enPotrero}/eventos")->assertJsonPath('data.0.id', $ev);
    $this->getJson("{$this->base}/sanidad/retiros")->assertJsonCount(2, 'lotes');
    expect(DB::table('cambios')->where('entidad', 'lote')->where('entidad_id', $enPotrero)->count())->toBeGreaterThanOrEqual(3);

    $nueva = animalNuevo(['categoria' => 'BUB_BUBILLA', 'lote_id' => $enPotrero]);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.individualizacion', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $enPotrero, 'conteo' => ['BUB_BUBILLA' => 1]],
        'payload' => ['animales' => [$nueva]], 'ocurrido_en' => '2026-10-05'])->assertCreated();
    $this->getJson("{$this->base}/animales/{$nueva['animal_id']}")->assertJsonPath('data.retiro_carne_hasta', '2026-10-31');
    $this->getJson("{$this->base}/animales/{$nueva['animal_id']}/eventos?modulo=sanidad")->assertJsonPath('data.0.id', $ev)->assertJsonPath('data.0.heredado_de_lote', true);

    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/lotes/{$enPotrero}")->assertNotFound();
    expect(fn () => DB::table('evento_lotes')->where('evento_id', $ev)->delete())->toThrow(QueryException::class); // append-only
});
