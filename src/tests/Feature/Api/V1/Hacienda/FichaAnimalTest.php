<?php

use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(duenoDe($this->est));
});

function ev(string $tipo, array $ids, array $payload, string $fecha, ?string $lote = null): array
{
    sort($ids);

    return ['tipo' => $tipo, 'sujetos' => array_filter(['tipo' => 'animales', 'animal_ids' => $ids, 'lote_id' => $lote]), 'payload' => $payload, 'ocurrido_en' => $fecha];
}

it('ficha: encabezado con identificadores, raza, categoría, edad, peso, estados, clase de faena y confianza', function () {
    $a = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUBILLO', 'castrado' => true, 'raza' => 'BUB_MEDITERRANEA', 'denticion' => '4',
        'fecha_nacimiento' => now()->subMonths(26)->toDateString(),
        'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'M-0301'], ['tipo' => 'tatuaje', 'valor' => 'E-301']]]);
    $this->postJson("{$this->base}/eventos", altaBody([$a], '2026-01-10'))->assertCreated();
    $this->postJson("{$this->base}/eventos", ev('hacienda.pesaje', [$a['animal_id']], ['modo' => 'individual', 'peso_kg' => '468', 'metodo' => 'balanza'], '2026-10-10'))->assertCreated();

    $this->getJson("{$this->base}/animales/{$a['animal_id']}", ['Accept-Language' => 'es'])->assertOk()
        ->assertJsonPath('data.identificacion_principal', 'M-0301')->assertJsonPath('data.raza', 'BUB_MEDITERRANEA')
        ->assertJsonPath('data.categoria_nombre', 'Bubillo')->assertJsonPath('data.edad_meses', 26)
        ->assertJsonPath('data.peso_kg', '468')->assertJsonPath('data.peso_fecha', '2026-10-10')
        ->assertJsonPath('data.clase_faena', ['codigo' => 'BUB_F_BBO_J', 'abreviatura' => 'BBO', 'clase' => 'pesado'])
        ->assertJsonPath('data.identificaciones', fn ($i) => count($i) === 2)
        ->assertJsonPath('data.confianza_resumen', ['eventos' => 2, 'con_integridad_registrada' => 0])
        ->assertJsonPath('data.eventos_count', 2);
});

it('timeline con autor, confianza y filtros por módulo; anulados solo si se piden', function () {
    $a = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$a], '2026-01-10'))->assertCreated();
    $this->postJson("{$this->base}/eventos", ev('sanidad.vacunacion', [$a['animal_id']], ['producto_nombre' => 'Antiaftosa', 'enfermedad' => 'aftosa'], '2026-03-01'))->assertCreated();
    $this->postJson("{$this->base}/eventos", ev('reproduccion.tacto', [$a['animal_id']], ['metodo' => 'tacto', 'resultado' => 'vacia'], '2026-04-01'))->assertCreated();

    $this->getJson("{$this->base}/animales/{$a['animal_id']}/eventos")->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.tipo', 'reproduccion.tacto')->assertJsonPath('data.0.autor.rol', 'dueno')
        ->assertJsonPath('data.0.confianza', 'declarado')->assertJsonPath('data.2.tipo', 'hacienda.alta');
    $this->getJson("{$this->base}/animales/{$a['animal_id']}/eventos?modulo=sanidad")->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.resumen', 'Antiaftosa (aftosa) — 1 animal');
});

it('hereda en el timeline los eventos grupales del lote anónimo anteriores a la individualización', function () {
    $lote = crearLote($this->est);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUCERRA' => 10]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-01-01'])->assertCreated();
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.vacunacion', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote],
        'payload' => ['producto_nombre' => 'Antirrábica', 'enfermedad' => 'rabia'], 'ocurrido_en' => '2026-02-01'])->assertCreated();
    $nueva = animalNuevo(['categoria' => 'BUB_BUCERRA', 'lote_id' => $lote, 'identificaciones' => [['tipo' => 'caravana_visual', 'valor' => 'B-1']]]);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.individualizacion', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUCERRA' => 1]],
        'payload' => ['animales' => [$nueva]], 'ocurrido_en' => '2026-03-01'])->assertCreated();

    $tl = $this->getJson("{$this->base}/animales/{$nueva['animal_id']}/eventos")->assertOk()->json('data');
    expect(collect($tl)->pluck('tipo')->all())->toBe(['hacienda.individualizacion', 'sanidad.vacunacion', 'hacienda.alta'])
        ->and(collect($tl)->pluck('heredado_de_lote')->all())->toBe([false, true, true]);
});

it('genealogía: madre del sistema y crías', function () {
    $madre = animalNuevo(['raza' => 'BUB_MURRAH']);
    $this->postJson("{$this->base}/eventos", altaBody([$madre], '2025-01-01'))->assertCreated();
    $cria = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUCERRO']);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'reproduccion.parto', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$madre['animal_id']]],
        'payload' => ['tipo' => 'normal', 'crias' => [['viva' => true, 'animal' => $cria]]], 'ocurrido_en' => '2026-01-20'])->assertCreated();

    $this->getJson("{$this->base}/animales/{$cria['animal_id']}/genealogia")->assertOk()
        ->assertJsonPath('data.madre.id', $madre['animal_id'])->assertJsonPath('data.padre', null);
    $this->getJson("{$this->base}/animales/{$madre['animal_id']}/genealogia")->assertOk()
        ->assertJsonPath('data.crias.0.id', $cria['animal_id']);
    $this->getJson("{$this->base}/animales/{$madre['animal_id']}/documentos")->assertOk()->assertExactJson(['data' => []]);
});

it('404 animal de otro establecimiento o inexistente; 403 no aplica a lectura (puede ver)', function () {
    $a = animalNuevo();
    $this->postJson("{$this->base}/eventos", altaBody([$a]))->assertCreated();

    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson("{$this->base}/animales/{$a['animal_id']}/eventos")->assertOk();

    $otro = crearEstablecimiento();
    Sanctum::actingAs(duenoDe($otro));
    $this->getJson("/api/v1/establecimientos/{$otro->id}/animales/{$a['animal_id']}/eventos")->assertNotFound();
    $this->getJson("/api/v1/establecimientos/{$otro->id}/animales/".Str::uuid7().'/genealogia')->assertNotFound();
    $this->getJson("{$this->base}/animales/{$a['animal_id']}")->assertNotFound();
});
