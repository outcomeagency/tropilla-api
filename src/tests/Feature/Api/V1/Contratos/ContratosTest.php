<?php

use App\Domain\Contratos\Actions\GestionarEntrega;
use App\Domain\Contratos\Actions\GestionarTomas;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    Http::fake(['*/forms/chromium/convert/html' => Http::response('%PDF-1.4 contrato', 200)]);
    $this->travelTo('2026-10-20 12:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->lote = crearLote($this->est, 'BUB', true, 'Bubillos 2027');
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'lote', 'lote_id' => $this->lote, 'conteo' => ['BUB_BUBILLO' => 40]],
        'payload' => ['motivo' => 'inventario_inicial'], 'ocurrido_en' => '2026-03-01'])->assertCreated();
    $this->input = ['lote_id' => $this->lote, 'peso_vivo_estimado_kg' => '480', 'precio_referencia_kg' => '5200', 'descuento_pct' => '8', 'anticipo_pct' => '30', 'fecha_entrega' => '2027-04-30'];
});

function contratoPublicado(object $t): array
{
    $c = $t->postJson("{$t->base}/contratos", $t->input)->assertCreated()->json('data');
    $t->postJson("{$t->base}/contratos/{$c['id']}/publicar")->assertOk();

    return DB::table('contratos_futuro')->where('id', $c['id'])->first() ? (array) DB::table('contratos_futuro')->where('id', $c['id'])->first() : [];
}

function comprador(): User
{
    return User::factory()->create(['email_verificado_en' => now()]);
}

it('calcula kg al gancho, tokens de 100 kg, precio especial y anticipo; borrador → publicar con huella, PDF y precios ocultos sin cuenta', function () {
    $t = $this->postJson("{$this->base}/contratos/calcular", $this->input)->assertOk()->json('data');
    expect($t)->toMatchArray(['cabezas' => 40, 'especie' => 'BUB', 'categoria' => 'BUB_BUBILLO', 'rendimiento_pct' => '53', 'kg_gancho_totales' => 10176,
        'kg_por_token' => 100, 'tokens_totales' => 101, 'precio_especial_kg' => '4784', 'anticipo_por_token' => '143520', 'tolerancia_pct' => '5', 'toma_vencimiento_horas' => 48]);

    $c = $this->postJson("{$this->base}/contratos", $this->input)->assertCreated()->assertJsonPath('data.estado', 'borrador')->assertJsonPath('data.huella', null)->json('data');
    $this->postJson("{$this->base}/contratos/{$c['id']}/terminos", ['descuento_pct' => '10'])->assertCreated()->assertJsonPath('data.terminos.precio_especial_kg', '4680')->assertJsonPath('data.version', 0);
    $this->postJson("{$this->base}/contratos/{$c['id']}/terminos", ['descuento_pct' => '8'])->assertCreated();
    $this->getJson("{$this->base}/contratos/{$c['id']}/preview")->assertOk()->assertJsonPath('data.precio_especial_kg', '4784');

    $p = $this->postJson("{$this->base}/contratos/{$c['id']}/publicar")->assertOk()->assertJsonPath('data.estado', 'publicado')->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.tokens_libres', 101)->assertJsonCount(1, 'data.versiones')->json('data');
    expect($p['huella'])->toHaveLength(64)->and($p['documento_id'])->not->toBeNull();
    $this->getJson("{$this->base}/documentos/{$p['documento_id']}")->assertJsonPath('data.tipo', 'contrato_futuro_pdf')->assertJsonPath('data.vinculos.0.tipo', 'contrato');
    $this->postJson("{$this->base}/contratos/{$c['id']}/publicar")->assertStatus(422);

    auth()->forgetGuards();
    $this->app['auth']->forgetGuards();
    $pub = $this->withHeaders(['Authorization' => ''])->getJson("/api/v1/public/contratos/{$p['codigo_publico']}")->assertOk()
        ->assertJsonPath('data.tokens_libres', 101)->assertJsonPath('data.kg_ofrecidos', 10100)->assertJsonPath('data.precio_especial_kg', null)->json('data');
    expect($pub['huella'])->toBe($p['huella']);
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('tomas: no más que los libres, confirmar «recibí», apalancamiento; mortalidad → recorte prorrata con anticipo a devolver', function () {
    $c = (object) contratoPublicado($this);
    $tomas = app(GestionarTomas::class);
    $t1 = $tomas->tomar($c->codigo_publico, comprador(), 60);
    $t2 = $tomas->tomar($c->codigo_publico, comprador(), 30);
    expect(fn () => $tomas->tomar($c->codigo_publico, comprador(), 12))->toThrow(ApiException::class);
    expect(fn () => $tomas->tomar($c->codigo_publico, $this->dueno, 1))->toThrow(ApiException::class); // el propio productor no

    $this->postJson("{$this->base}/contratos/{$c->id}/tomas/{$t1}/confirmar-pago")->assertOk()->assertJsonPath('data.estado', 'confirmada')
        ->assertJsonPath('data.anticipo_monto', '8611200')->assertJsonPath('data.movimientos.1.tipo', 'confirmada');
    $this->getJson("{$this->base}/contratos/{$c->id}")->assertJsonPath('data.estado', 'parcialmente_tomado')->assertJsonPath('data.tokens_libres', 11);
    $this->getJson("{$this->base}/apalancamiento")->assertOk()->assertJsonPath('data.anticipos_cobrados', '8611200')
        ->assertJsonPath('data.anticipos_pendientes', '4305600')->assertJsonPath('data.kg_comprometidos', 6000)->assertJsonPath('data.contratos.0.pct_tomado', '89.11');
    $this->postJson("{$this->base}/contratos/{$c->id}/tomas/{$t2}/confirmar-pago")->assertOk();

    // Con tomas no se cambia la economía; mortalidad: 20 cabezas → 50 tokens → 33 / 17 (prorrata, resto por mayor fracción).
    $this->postJson("{$this->base}/contratos/{$c->id}/terminos", ['descuento_pct' => '5', 'motivo' => 'Subió la hacienda'])->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $sim = $this->postJson("{$this->base}/contratos/{$c->id}/terminos?simular=true", ['cabezas' => 20, 'motivo' => 'Mortandad por inundación'])->assertOk()->json('data');
    expect($sim['terminos']['tokens_totales'])->toBe(50)->and($sim['recorte']['recortes'])->toBe([['toma_id' => $t1, 'de' => 60, 'a' => 33], ['toma_id' => $t2, 'de' => 30, 'a' => 17]]);
    $this->postJson("{$this->base}/contratos/{$c->id}/terminos", ['cabezas' => 20])->assertStatus(422)->assertJsonValidationErrors(['motivo']);
    $v2 = $this->postJson("{$this->base}/contratos/{$c->id}/terminos", ['cabezas' => 20, 'motivo' => 'Mortandad por inundación'])->assertCreated()
        ->assertJsonPath('data.version', 2)->assertJsonPath('data.estado', 'completo')->json('data');
    $porId = collect($v2['tomas'])->keyBy('id');
    expect($porId[$t1])->toMatchArray(['tokens_vigentes' => 33, 'anticipo_a_devolver' => '3875040'])
        ->and($porId[$t2])->toMatchArray(['tokens_vigentes' => 17, 'anticipo_a_devolver' => '1865760'])
        ->and(collect($porId[$t1]['movimientos'])->pluck('tipo')->last())->toBe('recorte_prorrata')
        ->and($v2['versiones'][1]['motivo'])->toBe('Mortandad por inundación');
    expect(DB::table('contrato_versiones')->where('contrato_id', $c->id)->where('version', 2)->value('sobre'))->toContain($v2['versiones'][0]['huella']);
    $this->postJson("{$this->base}/contratos/{$c->id}/tomas/{$t1}/devolucion-informada", ['referencia' => 'Transferencia 0042'])->assertOk()
        ->assertJsonPath('data.movimientos.3.tipo', 'devolucion_informada');
    expect(DB::table('notificaciones')->where('tipo', 'recorte_prorrata')->count())->toBe(2);
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('entrega dentro de la tolerancia: liquida por los reales, confirman productor y comprador → operación y contrato liquidado; fuera → a resolver', function () {
    $c = (object) contratoPublicado($this);
    $tomas = app(GestionarTomas::class);
    $comp = comprador();
    $t1 = $tomas->tomar($c->codigo_publico, $comp, 50);
    $this->postJson("{$this->base}/contratos/{$c->id}/tomas/{$t1}/confirmar-pago")->assertOk();

    $this->postJson("{$this->base}/contratos/{$c->id}/entrega", ['cabezas_entregadas' => 38, 'peso_vivo_total_kg' => '18000', 'rendimiento_real_pct' => '40'])
        ->assertOk()->assertJsonPath('data.estado', 'a_resolver')->assertJsonPath('data.dentro_tolerancia', false);
    $this->postJson("{$this->base}/contratos/{$c->id}/entrega/confirmar")->assertStatus(422);

    $e = $this->postJson("{$this->base}/contratos/{$c->id}/entrega", ['cabezas_entregadas' => 40, 'peso_vivo_total_kg' => '19500', 'rendimiento_real_pct' => '52.5'])->assertOk()
        ->assertJsonPath('data.kg_gancho_reales', 10237)->assertJsonPath('data.dentro_tolerancia', true)->assertJsonPath('data.estado', 'cargada')->json('data');
    expect($e['liquidacion'][0])->toMatchArray(['toma_id' => $t1, 'kg_efectivos' => '5030', 'importe' => '24063520', 'anticipo' => '7176000', 'saldo' => '16887520']);
    $this->postJson("{$this->base}/contratos/{$c->id}/entrega/confirmar")->assertOk()->assertJsonPath('data.estado', 'confirmada_productor');
    $this->getJson("{$this->base}/contratos/{$c->id}")->assertJsonPath('data.estado', 'entregado');

    app(GestionarEntrega::class)->confirmar($this->est, DB::table('contratos_futuro')->where('id', $c->id)->first(), $comp, 'comprador');
    $f = $this->getJson("{$this->base}/contratos/{$c->id}")->assertJsonPath('data.estado', 'liquidado')->assertJsonPath('data.entrega.estado', 'confirmada')->json('data');
    $e = DB::table('entregas')->where('contrato_id', $c->id)->first();
    expect($e->hash)->toHaveLength(64)->and($e->operacion_id)->not->toBeNull()->and($f['entrega']['documento_id'])->not->toBeNull();
    $this->getJson("{$this->base}/operaciones/{$e->operacion_id}")->assertJsonPath('data.modalidad', 'al_gancho')->assertJsonPath('data.contrato_id', $c->id)
        ->assertJsonPath('data.precio_kg', '4784');
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('respeta el compromiso (oferta/contrato → 409), cancelar con motivo devuelve anticipos y libera; permisos', function () {
    $loteInd = crearLote($this->est, 'BUB', false, 'Bubillos marcados');
    $an = [animalNuevo(['categoria' => 'BUB_BUBILLO', 'sexo' => 'M', 'lote_id' => $loteInd]), animalNuevo(['categoria' => 'BUB_BUBILLO', 'sexo' => 'M', 'lote_id' => $loteInd])];
    $this->postJson("{$this->base}/eventos", altaBody($an, '2026-03-01'))->assertCreated();
    $c = $this->postJson("{$this->base}/contratos", [...$this->input, 'lote_id' => $loteInd, 'peso_vivo_estimado_kg' => '480'])->assertCreated()->json('data');
    $this->postJson("{$this->base}/contratos/{$c['id']}/publicar")->assertOk();
    $this->getJson("{$this->base}/animales/{$an[0]['animal_id']}")->assertJsonPath('data.compromiso', 'en_contrato');
    $this->postJson("{$this->base}/contratos", [...$this->input, 'lote_id' => $loteInd])->assertCreated();
    $otro = DB::table('contratos_futuro')->where('lote_id', $loteInd)->where('estado', 'borrador')->value('id');
    $this->postJson("{$this->base}/contratos/{$otro}/publicar")->assertStatus(409)->assertJsonPath('code', 'comprometido');

    $comp = comprador();
    $cod = DB::table('contratos_futuro')->where('id', $c['id'])->value('codigo_publico');
    $t = app(GestionarTomas::class)->tomar($cod, $comp, 1);
    $this->postJson("{$this->base}/contratos/{$c['id']}/tomas/{$t}/confirmar-pago")->assertOk();
    $this->postJson("{$this->base}/contratos/{$c['id']}/cancelar", ['motivo' => 'Se inundó el campo'])->assertOk()->assertJsonPath('data.estado', 'cancelado')
        ->assertJsonPath('data.motivo_estado', 'Se inundó el campo')->assertJsonPath('data.tomas.0.anticipo_a_devolver', '143520');
    $this->getJson("{$this->base}/animales/{$an[0]['animal_id']}")->assertJsonPath('data.compromiso', 'libre');

    $this->postJson("{$this->base}/contratos", ['lote_id' => $this->lote, 'peso_vivo_estimado_kg' => '1', 'precio_referencia_kg' => '0', 'descuento_pct' => '120', 'anticipo_pct' => '30', 'fecha_entrega' => '2026-01-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['precio_referencia_kg', 'descuento_pct']);
    $this->postJson("{$this->base}/contratos", [...$this->input, 'cabezas' => 41])->assertStatus(422)->assertJsonValidationErrors(['cabezas']);

    Sanctum::actingAs(miembro($this->est, 'asesor'));
    $this->getJson("{$this->base}/contratos/{$c['id']}")->assertOk()->assertJsonPath('data.tomas.0.anticipo_monto', null)->assertJsonPath('data.tomas.0.comprador', null);
    $this->postJson("{$this->base}/contratos", $this->input)->assertForbidden();
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson("{$this->base}/contratos")->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/contratos/{$c['id']}")->assertNotFound();
});
