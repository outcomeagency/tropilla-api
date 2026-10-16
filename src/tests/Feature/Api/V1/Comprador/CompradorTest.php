<?php

use App\Domain\Perfil\Services\Perfiles;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    Http::fake(['*' => Http::response('%PDF-1.4 x', 200)]);
    $this->travelTo('2026-10-20 12:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $lote = crearLote($this->est, 'BUB', true, 'Bubillos 2027');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUBILLO' => 40]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-03-01'])->assertCreated();
    $this->contrato = $this->postJson("{$this->base}/contratos", ['lote_id' => $lote, 'peso_vivo_estimado_kg' => '480', 'precio_referencia_kg' => '5200',
        'descuento_pct' => '8', 'anticipo_pct' => '30', 'fecha_entrega' => '2027-04-30'])->assertCreated()->json('data');
    $this->postJson("{$this->base}/contratos/{$this->contrato['id']}/publicar")->assertOk();
    $this->codigo = $this->contrato['codigo_publico'];
    $this->comprador = User::factory()->create(['email_verificado_en' => now()]);
});

it('cuenta liviana → precio especial visible → tomar N tokens (≤ libres, idempotente) → «pagué» → «recibí» → Mis contratos', function () {
    Sanctum::actingAs($this->comprador);
    $this->postJson("/api/v1/contratos/{$this->codigo}/tomas", ['tokens' => 10])->assertStatus(403)->assertJsonPath('code', 'perfil_comprador_requerido');
    $this->getJson('/api/v1/me/comprador')->assertNotFound();
    $this->putJson('/api/v1/me/comprador', ['empresa' => 'Frigorífico del Litoral', 'tipo' => 'frigorifico', 'pais' => 'ar', 'idiomas' => ['es', 'en']])->assertOk()
        ->assertJsonPath('data.pais', 'AR')->assertJsonPath('data.idiomas', ['es', 'en']);
    $this->getJson("/api/v1/public/contratos/{$this->codigo}")->assertJsonPath('data.precio_especial_kg', '4784')->assertJsonPath('data.anticipo_por_token', '143520');

    $t = $this->postJson("/api/v1/contratos/{$this->codigo}/tomas", ['tokens' => 40], ['Idempotency-Key' => 'abc-1'])->assertCreated()
        ->assertJsonPath('data.estado', 'reservada')->assertJsonPath('data.kg', 4000)->assertJsonPath('data.anticipo_monto', '5740800')
        ->assertJsonPath('data.contrato.codigo', $this->codigo)->assertJsonPath('data.contrato.fecha_entrega', '2027-04-30')->json('data');
    $this->postJson("/api/v1/contratos/{$this->codigo}/tomas", ['tokens' => 40], ['Idempotency-Key' => 'abc-1'])->assertOk()->assertJsonPath('data.id', $t['id']);
    $this->postJson("/api/v1/contratos/{$this->codigo}/tomas", ['tokens' => 62])->assertStatus(409)->assertJsonPath('code', 'tokens_insuficientes');
    expect(DB::table('notificaciones')->where('usuario_id', $this->dueno->id)->where('tipo', 'toma_nueva')->count())->toBe(1);

    $this->postJson("/api/v1/comprador/tomas/{$t['id']}/pago-informado", ['referencia' => 'Transferencia 1234', 'fecha_pago' => '2026-10-20'])->assertOk()
        ->assertJsonPath('data.estado', 'pago_informado');
    Sanctum::actingAs($this->dueno);
    $this->getJson('/api/v1/establecimientos/'.$this->est->id.'/inicio')->assertJsonPath('data.pendientes.tomas_por_confirmar', 1);
    $this->postJson("{$this->base}/contratos/{$this->contrato['id']}/tomas/{$t['id']}/confirmar-pago")->assertOk()->assertJsonPath('data.estado', 'confirmada')
        ->assertJsonPath('data.comprador.empresa', 'Frigorífico del Litoral');

    Sanctum::actingAs($this->comprador);
    $this->getJson('/api/v1/comprador/tomas?estado=vigentes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'confirmada')
        ->assertJsonPath('data.0.movimientos.2.tipo', 'confirmada');
    $this->travelTo('2026-10-25 12:00:00');
    $this->artisan('contratos:liberar-tomas'); // confirmada: no se libera
    $this->getJson('/api/v1/comprador/tomas')->assertJsonPath('data.0.estado', 'confirmada');
});

it('la toma sin «recibí» se libera a las 48 h y los tokens vuelven a estar libres; devolución confirmada; entrega confirmada por el comprador', function () {
    Sanctum::actingAs($this->comprador);
    $this->putJson('/api/v1/me/comprador', ['empresa' => 'Exportadora', 'tipo' => 'exportador', 'pais' => 'CN'])->assertOk();
    $vencera = $this->postJson("/api/v1/contratos/{$this->codigo}/tomas", ['tokens' => 30])->assertCreated()->json('data.id');
    $this->travelTo('2026-10-22 12:00:01');
    $this->artisan('contratos:liberar-tomas')->expectsOutput('Tomas liberadas: 1');
    $this->getJson('/api/v1/comprador/tomas?estado=liberada')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $vencera)->assertJsonPath('data.0.tokens_vigentes', 0);
    $this->getJson("/api/v1/public/contratos/{$this->codigo}")->assertJsonPath('data.tokens_libres', 101);
    expect(DB::table('notificaciones')->where('usuario_id', $this->comprador->id)->where('tipo', 'toma_liberada')->count())->toBe(1);

    $t = $this->postJson("/api/v1/contratos/{$this->codigo}/tomas", ['tokens' => 50])->json('data.id');
    Sanctum::actingAs($this->dueno);
    $this->postJson("{$this->base}/contratos/{$this->contrato['id']}/tomas/{$t}/confirmar-pago")->assertOk();
    $this->postJson("{$this->base}/contratos/{$this->contrato['id']}/terminos", ['cabezas' => 15, 'motivo' => 'Mortandad'])->assertCreated();
    $this->postJson("{$this->base}/contratos/{$this->contrato['id']}/tomas/{$t}/devolucion-informada", ['referencia' => 'Transf. 99'])->assertOk();
    Sanctum::actingAs($this->comprador);
    $this->postJson("/api/v1/comprador/tomas/{$t}/devolucion-confirmada")->assertOk()->assertJsonPath('data.estado', 'devuelta_parcial');

    Sanctum::actingAs($this->dueno);
    $this->postJson("{$this->base}/contratos/{$this->contrato['id']}/entrega", ['cabezas_entregadas' => 15, 'peso_vivo_total_kg' => '7200', 'rendimiento_real_pct' => '53'])->assertOk();
    $this->postJson("{$this->base}/contratos/{$this->contrato['id']}/entrega/confirmar")->assertOk();
    Sanctum::actingAs($this->comprador);
    $e = $this->postJson("/api/v1/comprador/tomas/{$t}/entrega/confirmar")->assertOk()->assertJsonPath('data.estado', 'confirmada')
        ->assertJsonCount(1, 'data.liquidacion')->json('data');
    // 7.200 kg × 53 % = 3.816 kg reales = contratados → factor 1: 38 tokens = 3.800 kg; anticipo neto = 38 × 143.520
    expect($e['liquidacion'][0])->toMatchArray(['kg_efectivos' => '3800', 'importe' => '18179200', 'anticipo' => '5453760', 'saldo' => '12725440']);
    $this->getJson('/api/v1/comprador/tomas')->assertJsonPath('data.0.contrato.estado', 'liquidado');
    $otro = User::factory()->create();
    Sanctum::actingAs($otro);
    $this->postJson("/api/v1/comprador/tomas/{$t}/entrega/confirmar")->assertNotFound();
});

it('seguir productores: aviso de contrato nuevo; dejar de seguir; 404 sin perfil publicado; 422', function () {
    Perfiles::asegurar($this->est);
    DB::table('perfiles_publicos')->where('establecimiento_id', $this->est->id)->update(['slug' => 'la-esperanza', 'publicado' => true, 'preview_aceptada_en' => now()]);
    Sanctum::actingAs($this->comprador);
    $this->postJson('/api/v1/seguimientos', ['establecimiento_slug' => 'la-esperanza'])->assertCreated()->assertJsonPath('data.establecimiento.nombre', 'La Esperanza');
    $this->getJson('/api/v1/seguimientos')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/public/establecimientos/la-esperanza/contratos')->assertJsonCount(1, 'data')->assertJsonPath('data.0.precio_especial_kg', null);

    Sanctum::actingAs($this->dueno);
    $lote = crearLote($this->est, 'BUB', true, 'Otro');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $lote, 'conteo' => ['BUB_BUBILLA' => 10]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-03-01'])->assertCreated();
    $c2 = $this->postJson("{$this->base}/contratos", ['lote_id' => $lote, 'peso_vivo_estimado_kg' => '400', 'precio_referencia_kg' => '5000', 'descuento_pct' => '5',
        'anticipo_pct' => '20', 'fecha_entrega' => '2027-06-30'])->json('data');
    $this->postJson("{$this->base}/contratos/{$c2['id']}/publicar")->assertOk();
    expect(DB::table('notificaciones')->where('usuario_id', $this->comprador->id)->where('tipo', 'contrato_nuevo')->count())->toBe(1);

    Sanctum::actingAs($this->comprador);
    $this->deleteJson('/api/v1/seguimientos/la-esperanza')->assertNoContent();
    $this->getJson('/api/v1/seguimientos')->assertJsonCount(0, 'data');
    expect(DB::table('seguimientos')->whereNotNull('dejado_en')->count())->toBe(1);
    $this->postJson('/api/v1/seguimientos', ['establecimiento_slug' => 'no-existe'])->assertNotFound();
    $this->putJson('/api/v1/me/comprador', ['empresa' => '', 'tipo' => 'vecino', 'pais' => 'ARG'])->assertStatus(422)->assertJsonValidationErrors(['empresa', 'tipo', 'pais']);
    $this->postJson("/api/v1/contratos/{$this->codigo}/tomas", ['tokens' => 0])->assertStatus(422);
    $this->postJson('/api/v1/contratos/ZZZZZZZZZZ/tomas', ['tokens' => 1])->assertStatus(403); // sin perfil de comprador todavía
});
