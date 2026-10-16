<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->enc = miembro($this->est, 'encargado'));
});

function productoSanitario(object $t, array $extra = []): string
{
    return test()->postJson("{$t->base}/productos-sanitarios", array_merge([
        'nombre_comercial' => 'Doramec', 'principio_activo' => 'doramectina', 'tipo' => 'antiparasitario_interno', 'via' => 'subcutanea',
        'retiro_carne_dias' => 35, 'retiro_leche_dias' => 0, 'especies' => ['BUB'],
    ], $extra))->assertCreated()->json('data.id');
}

function retiroDe(string $animalId): ?string
{
    return DB::table('animales_estado')->where('animal_id', $animalId)->value('retiro_carne_hasta');
}

it('desparasitación a un lote en un paso: se expande a sus animales y copia el retiro del producto al payload', function () {
    $lote = crearLote($this->est, 'BUB', false);
    $animales = [animalNuevo(['lote_id' => $lote]), animalNuevo(['lote_id' => $lote]), animalNuevo(['lote_id' => $lote])];
    $this->postJson("{$this->base}/eventos", altaBody($animales, '2026-09-01'))->assertCreated();
    $prod = productoSanitario($this);

    $ev = $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.desparasitacion', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote],
        'payload' => ['producto_id' => $prod, 'tipo' => 'interno', 'peso_dosificacion_kg' => '380', 'metodo_dosificacion' => 'por_el_mas_pesado'],
        'ocurrido_en' => '2026-10-01'])->assertCreated();

    $ids = array_column($animales, 'animal_id');
    sort($ids);
    $ev->assertJsonPath('data.sujetos.tipo', 'animales')->assertJsonPath('data.sujetos.lote_id', $lote)->assertJsonPath('data.sujetos.animal_ids', $ids)
        ->assertJsonPath('data.payload.retiro_carne_dias', 35)->assertJsonPath('data.payload.retiro_leche_dias', 0)
        ->assertJsonPath('data.payload.producto_nombre', 'Doramec')->assertJsonPath('data.payload.principio_activo', 'doramectina');
    foreach ($ids as $id) {
        expect(retiroDe($id))->toBe('2026-11-05');
    }
    $this->getJson("{$this->base}/animales/{$ids[0]}/eventos?modulo=sanidad")->assertJsonPath('data.0.tipo', 'sanidad.desparasitacion');

    // Editar el producto no reescribe lo aplicado (snapshot en el payload).
    $this->patchJson("{$this->base}/productos-sanitarios/{$prod}", ['row_version' => 1, 'retiro_carne_dias' => 60])->assertOk();
    $this->postJson('/api/v1/establecimientos/'.$this->est->id.'/eventos', ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$ids[0]]],
        'payload' => ['modo' => 'individual', 'peso_kg' => '400', 'metodo' => 'balanza'], 'ocurrido_en' => '2026-10-02'])->assertCreated();
    expect(retiroDe($ids[0]))->toBe('2026-11-05');
});

it('vacunación a un potrero: el servidor fija los animales presentes; tratamiento corre el retiro desde el último día', function () {
    $potrero = crearPotrero($this->est);
    $loteEnPotrero = crearLote($this->est, 'BUB', false, 'Recría');
    $a1 = animalNuevo(['potrero_id' => $potrero]);
    $a2 = animalNuevo(['lote_id' => $loteEnPotrero]);
    $fuera = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$a1, $a2, $fuera], '2026-09-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.traslado', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $loteEnPotrero],
        'payload' => ['potrero_destino_id' => $potrero], 'ocurrido_en' => '2026-09-02'])->assertCreated();
    $vac = productoSanitario($this, ['nombre_comercial' => 'Aftosa oleosa', 'tipo' => 'vacuna', 'principio_activo' => null, 'retiro_carne_dias' => 21, 'especies' => ['BOV', 'BUB']]);

    $ev = $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.vacunacion', 'sujetos' => ['tipo' => 'potrero', 'potrero_id' => $potrero],
        'payload' => ['producto_id' => $vac, 'enfermedad' => 'aftosa', 'numero_dosis' => 'primera', 'campana' => '2026-2', 'vacunador' => 'Ramón Gómez',
            'envase_lote' => 'A-2231', 'envase_vence' => '2027-03-31', 'dosis_aplicada' => '2 ml'],
        'ocurrido_en' => '2026-10-01'])->assertCreated()->assertJsonPath('data.sujetos.tipo', 'potrero')->assertJsonPath('data.payload.via', 'subcutanea');
    $afectados = DB::table('evento_animales')->where('evento_id', $ev->json('data.id'))->pluck('animal_id')->sort()->values()->all();
    $esperados = [$a1['animal_id'], $a2['animal_id']];
    sort($esperados);
    expect($afectados)->toBe($esperados)->and(retiroDe($a1['animal_id']))->toBe('2026-10-22')->and(retiroDe($fuera['animal_id']))->toBeNull();

    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.tratamiento', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$fuera['animal_id']]],
        'payload' => ['diagnostico_presuntivo' => 'Queratoconjuntivitis', 'producto_nombre' => 'Oxitetraciclina LA', 'duracion_dias' => 3, 'retiro_carne_dias' => 28, 'veterinario' => 'Vet. Pérez'],
        'ocurrido_en' => '2026-10-01'])->assertCreated();
    expect(retiroDe($fuera['animal_id']))->toBe('2026-10-31'); // 01/10 + 2 días de tratamiento + 28
});

it('advertencias: retiro menor al del producto, envase vencido y producto de otra especie (confirmar registra)', function () {
    $vaca = animalNuevo(['especie' => 'BOV', 'categoria' => 'BOV_VACA']);
    $this->postJson("{$this->base}/eventos", altaBody([$vaca], '2026-09-01'))->assertCreated();
    $prod = productoSanitario($this);
    $body = ['tipo' => 'sanidad.desparasitacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$vaca['animal_id']]],
        'payload' => ['producto_id' => $prod, 'tipo' => 'interno', 'retiro_carne_dias' => 10], 'ocurrido_en' => '2026-10-01'];

    $r = $this->postJson("{$this->base}/eventos", $body)->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion');
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toContain('retiro_menor_al_producto', 'producto_otra_especie');
    $this->postJson("{$this->base}/eventos", [...$body, 'confirmar_advertencias' => true])->assertCreated()
        ->assertJsonPath('data.payload.retiro_carne_dias', 10);

    $vencida = ['tipo' => 'sanidad.vacunacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$vaca['animal_id']]],
        'payload' => ['producto_nombre' => 'Rabia', 'enfermedad' => 'rabia', 'envase_vence' => '2026-01-31'], 'ocurrido_en' => '2026-10-01'];
    $r = $this->postJson("{$this->base}/eventos", $vencida)->assertStatus(422);
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toBe(['envase_vencido']);
});

it('422 (enfermedad fuera de catálogo, producto inexistente, sin nombre), 403 lectura, 404 ajeno', function () {
    $a = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$a], '2026-09-01'))->assertCreated();
    $suj = ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]];

    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.vacunacion', 'sujetos' => $suj, 'payload' => ['producto_nombre' => 'X', 'enfermedad' => 'gripe'], 'ocurrido_en' => '2026-10-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['payload.enfermedad']);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.vacunacion', 'sujetos' => $suj, 'payload' => ['producto_id' => (string) Str::uuid7(), 'producto_nombre' => 'X', 'enfermedad' => 'aftosa'], 'ocurrido_en' => '2026-10-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['payload.producto_id']);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.desparasitacion', 'sujetos' => $suj, 'payload' => ['tipo' => 'interno'], 'ocurrido_en' => '2026-10-01'])
        ->assertStatus(422);

    $ok = ['tipo' => 'sanidad.vacunacion', 'sujetos' => $suj, 'payload' => ['producto_nombre' => 'Aftosa', 'enfermedad' => 'aftosa'], 'ocurrido_en' => '2026-10-01'];
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->postJson("{$this->base}/eventos", $ok)->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->postJson("{$this->base}/eventos", $ok)->assertNotFound();
});
