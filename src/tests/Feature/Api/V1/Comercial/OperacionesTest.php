<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    Http::fake(['*/forms/chromium/convert/html' => Http::response('%PDF-1.4 resumen del trato', 200)]);
    $this->travelTo('2026-10-12 15:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->lote = crearLote($this->est, 'BUB', false, 'Bubillos venta');
    $this->bubillos = [animalNuevo(['categoria' => 'BUB_BUBILLO', 'sexo' => 'M', 'lote_id' => $this->lote]), animalNuevo(['categoria' => 'BUB_BUBILLO', 'sexo' => 'M', 'lote_id' => $this->lote])];
    $this->postJson("{$this->base}/eventos", altaBody($this->bubillos, '2026-01-10'))->assertCreated();
    $this->ids = array_column($this->bubillos, 'animal_id');
    sort($this->ids);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.desparasitacion', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$this->ids[0]]],
        'payload' => ['producto_nombre' => 'Doramec', 'tipo' => 'interno', 'retiro_carne_dias' => 35], 'ocurrido_en' => '2026-10-01'])->assertCreated();
});

function ventaBody(array $ids, array $extra = []): array
{
    return array_replace_recursive(['tipo' => 'venta', 'fecha' => '2026-10-12', 'especie' => 'BUB', 'categoria' => 'BUB_BUBILLO', 'cantidad' => 2, 'animal_ids' => $ids,
        'trato' => ['comprador' => 'Frigorífico del Litoral', 'destino' => 'faena', 'lugar_pesada' => 'Balanza del campo', 'hora_pesada' => '07:30',
            'modalidad' => 'en_pie', 'desbaste_pct' => '6', 'tolerancias' => '±2 % de peso', 'plazo_pago_dias' => 30, 'flete_a_cargo' => 'vendedor', 'comision_pct' => '3'],
        'liquidacion' => ['peso_bruto_kg' => '936', 'precio_kg' => '2500', 'flete' => '45000'],
        'trazabilidad' => ['dte_numero' => '18-123456-7', 'renspa_destino' => '18.063.0.00999/00']], $extra);
}

it('registra la venta con el trato: liquidación, PDF en Documentos con huella, compromiso vendido y advertencia de retiro', function () {
    $r = $this->postJson("{$this->base}/operaciones", ventaBody($this->ids))->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion');
    expect(collect($r->json('advertencias'))->pluck('codigo')->all())->toBe(['retiro_vigente']);

    $op = $this->postJson("{$this->base}/operaciones", ventaBody($this->ids, ['confirmar_advertencias' => true]))->assertCreated()
        ->assertJsonPath('data.estado', 'registrada')->assertJsonPath('data.version', 1)->assertJsonPath('data.contraparte', 'Frigorífico del Litoral')
        ->assertJsonPath('data.precio_kg', '2500')->assertJsonPath('data.liquidacion.peso_neto_kg', '879.8')->assertJsonPath('data.liquidacion.subtotal', '2199500')
        ->assertJsonPath('data.liquidacion.comision', '65985')->assertJsonPath('data.liquidacion.neto_total', '2088515')->assertJsonPath('data.neto_total', '2088515')
        ->assertJsonPath('data.neto_kg_vivo', '2231.32')->assertJsonPath('data.trazabilidad.dte_numero', '18-123456-7')->assertJsonPath('data.animal_ids', $this->ids)
        ->assertJsonPath('data.confianza', 'declarado')->json('data');

    $doc = $this->getJson("{$this->base}/documentos/{$op['documento_id']}")->assertOk()->assertJsonPath('data.tipo', 'resumen_trato')
        ->assertJsonPath('data.privacidad', 'comercial')->assertJsonPath('data.vinculos.0.tipo', 'operacion')->assertJsonPath('data.vinculos.0.id', $op['id'])->json('data');
    $pdfSha = hash('sha256', '%PDF-1.4 resumen del trato');
    expect($doc['versiones'][0]['archivos'][0]['sha256'])->toBe($pdfSha)
        ->and(DB::table('operacion_versiones')->where('operacion_id', $op['id'])->value('pdf_sha256'))->toBe($pdfSha);
    Http::assertSent(fn ($req) => str_contains($req->url(), 'convert/html'));

    $this->getJson("{$this->base}/animales/{$this->ids[1]}")->assertJsonPath('data.compromiso', 'vendido');
    $this->postJson("{$this->base}/operaciones", ventaBody([$this->ids[1]], ['cantidad' => 1, 'confirmar_advertencias' => true]))->assertStatus(409)->assertJsonPath('code', 'comprometido');
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('corrección versionada y encadenada (nuevo PDF), flete a cargo del comprador, anulación libera el compromiso y revoca el resumen', function () {
    $op = $this->postJson("{$this->base}/operaciones", ventaBody($this->ids, ['confirmar_advertencias' => true]))->assertCreated()->json('data');
    $c = $this->postJson("{$this->base}/operaciones/{$op['id']}/correcciones", ventaBody($this->ids, ['motivo' => 'El flete lo pagó el frigorífico', 'confirmar_advertencias' => true,
        'trato' => ['flete_a_cargo' => 'comprador'], 'liquidacion' => ['precio_kg' => '2550']]))->assertCreated()
        ->assertJsonPath('data.version', 2)->assertJsonPath('data.precio_kg', '2550')->assertJsonPath('data.liquidacion.gastos', '0')
        ->assertJsonPath('data.liquidacion.flete_a_cargo', 'comprador')->json('data');
    expect(DB::table('operacion_versiones')->where('operacion_id', $op['id'])->where('version', 2)->value('hash_anterior'))->toBe($op['huella']);
    $this->getJson("{$this->base}/documentos/{$op['documento_id']}")->assertJsonPath('data.version_vigente', 2);

    $this->postJson("{$this->base}/operaciones/{$op['id']}/anulaciones", ['motivo' => 'Se cayó la venta'])->assertCreated()
        ->assertJsonPath('data.estado', 'anulada')->assertJsonPath('data.version', 3)->assertJsonPath('data.precio_kg', '2550');
    $this->getJson("{$this->base}/documentos/{$op['documento_id']}")->assertJsonPath('data.estado', 'revocado');
    $this->getJson("{$this->base}/animales/{$this->ids[1]}")->assertJsonPath('data.compromiso', 'libre');
    $this->postJson("{$this->base}/operaciones/{$op['id']}/correcciones", ventaBody($this->ids, ['motivo' => 'x y z']))->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->getJson("{$this->base}/operaciones?especie=BUB")->assertJsonCount(1, 'data');
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('sin Gotenberg la operación se registra igual (sin PDF); al gancho con rendimiento de la especie', function () {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('caído', 500)]);
    $this->postJson("{$this->base}/operaciones", ventaBody([], ['animal_ids' => [], 'trato' => ['modalidad' => 'al_gancho'], 'liquidacion' => ['precio_kg' => '4700']]))
        ->assertCreated()->assertJsonPath('data.documento_id', null)->assertJsonPath('data.modalidad', 'al_gancho')
        ->assertJsonPath('data.liquidacion.rendimiento_pct', '53')->assertJsonPath('data.liquidacion.kg_liquidables', '496.1');
});

it('privado: encargado/asesor 403, no viaja por sync; 422; 404 ajeno', function () {
    $op = $this->postJson("{$this->base}/operaciones", ventaBody($this->ids, ['confirmar_advertencias' => true]))->assertCreated()->json('data');
    $this->postJson("{$this->base}/operaciones", ['tipo' => 'trueque', 'fecha' => '12/10/2026', 'especie' => 'BUB', 'categoria' => 'BOV_VACA', 'cantidad' => 0,
        'trato' => ['modalidad' => 'a ojo', 'destino' => 'luna'], 'liquidacion' => ['peso_bruto_kg' => '0', 'precio_kg' => '1,5']])
        ->assertStatus(422)->assertJsonValidationErrors(['tipo', 'fecha', 'categoria', 'cantidad', 'trato.modalidad', 'trato.destino', 'liquidacion.peso_bruto_kg', 'liquidacion.precio_kg']);

    foreach (['encargado', 'asesor'] as $rol) {
        Sanctum::actingAs($u = miembro($this->est, $rol));
        $this->getJson("{$this->base}/operaciones")->assertForbidden();
        $this->getJson("{$this->base}/operaciones/{$op['id']}")->assertForbidden();
    }
    $cambios = collect($this->getJson("/api/v1/sync/pull?establecimiento_id={$this->est->id}")->json('data.cambios'));
    expect($cambios->pluck('entidad')->all())->not->toContain('operacion')
        ->and($cambios->where('entidad', 'documento')->pluck('id')->all())->not->toContain($op['documento_id']);
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/operaciones/{$op['id']}")->assertNotFound();
});
