<?php

use App\Domain\Integridad\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->lote = crearLote($this->est, 'BUB', false, 'Bubillos venta');
    $this->animales = [];
    foreach ([460, 468, 476] as $i => $kg) {
        $a = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUBILLO', 'castrado' => true, 'raza' => 'BUB_MEDITERRANEA', 'denticion' => '4',
            'lote_id' => $this->lote, 'fecha_nacimiento' => now()->subMonths(25 + $i)->toDateString()]);
        $this->animales[] = $a;
    }
    $this->postJson("{$this->base}/eventos", altaBody($this->animales, now()->subMonths(3)->toDateString()))->assertCreated();
    foreach ($this->animales as $i => $a) {
        $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.pesaje', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a['animal_id']]],
            'payload' => ['modo' => 'individual', 'peso_kg' => (string) [460, 468, 476][$i], 'metodo' => 'balanza'], 'ocurrido_en' => now()->subDays(5)->toDateString()])->assertCreated();
    }
});

it('borrador desde lote con precarga (peso, edad, dentición, clase faena) → preview → publicar hasheado → ficha pública', function () {
    $o = $this->postJson("{$this->base}/ofertas/desde-lote", ['tipo' => 'gordo', 'lote_id' => $this->lote])->assertCreated()
        ->assertJsonPath('data.estado', 'borrador')->assertJsonPath('data.cantidad', 3)->assertJsonPath('data.especie', 'BUB')
        ->assertJsonPath('data.precarga.peso.promedio_kg', '468')->assertJsonPath('data.precarga.peso.metodo', 'balanza')
        ->assertJsonPath('data.precarga.clase_faena', ['codigo' => 'BUB_F_BBO_J', 'abreviatura' => 'BBO', 'clase' => 'pesado'])
        ->assertJsonPath('data.precarga.edad.min_meses', 25)->assertJsonPath('data.avisos', [])
        ->assertJsonPath('data.textos.es.titulo', '3 Bubillo Mediterránea')->json('data');

    $this->getJson("{$this->base}/ofertas/{$o['id']}/preview?locale=en")->assertOk()->assertJsonPath('data.contenido.categorias.0.nombre', 'Buffalo steer')
        ->assertJsonPath('data.leyenda', fn ($l) => str_contains($l, 'does not certify'));

    $this->patchJson("{$this->base}/ofertas/{$o['id']}", ['row_version' => 1, 'condiciones' => ['modalidad' => 'en_pie', 'mostrar_precio' => false, 'precio_kg' => '1300'],
        'textos' => ['es' => ['titulo' => '3 bubillos Mediterráneos', 'descripcion' => 'Terminados a pasto en humedal.']]])->assertOk();
    $pub = $this->postJson("{$this->base}/ofertas/{$o['id']}/publicar")->assertOk()->assertJsonPath('data.estado', 'publicada')
        ->assertJsonPath('data.publicacion_vigente', 1);

    $fila = DB::table('oferta_publicaciones')->sole();
    expect(CanonicalJson::huella(CanonicalJson::decode(DB::table('oferta_publicaciones')->selectRaw('sobre::text as s')->value('s'))))->toBe($fila->hash)
        ->and(DB::table('registros_integridad')->where('sujeto_tipo', 'oferta_publicacion')->count())->toBe(1);

    $this->app['auth']->forgetGuards();
    $p = $this->getJson("/api/v1/public/ofertas/{$o['codigo_publico']}?locale=es")->assertOk()
        ->assertJsonPath('data.version', 1)->assertJsonPath('data.huella', $fila->hash)->assertJsonPath('data.contenido.titulo', '3 bubillos Mediterráneos')
        ->assertJsonPath('data.contenido.condiciones.mostrar_precio', false)
        ->assertJsonPath('data.establecimiento.zona', 'Mercedes, Corrientes');
    expect(json_encode($p->json()))->not->toContain('1300') // el precio no sale sin mostrar_precio
        ->not->toContain('renspa')->not->toContain('cuit')->not->toContain('reactor');
    $this->artisan('integridad:verificar-cadenas')->assertSuccessful();
});

it('avisos: pesaje viejo y retiro vigente exigen confirmar para publicar', function () {
    $this->travel(70)->days();
    $a = $this->animales[0]['animal_id'];
    $this->postJson("{$this->base}/eventos", ['tipo' => 'sanidad.tratamiento', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => [$a]],
        'payload' => ['diagnostico_presuntivo' => 'Queratitis', 'retiro_carne_dias' => 21], 'ocurrido_en' => now()->toDateString()])->assertCreated();
    $o = $this->postJson("{$this->base}/ofertas/desde-lote", ['tipo' => 'gordo', 'lote_id' => $this->lote])->json('data');

    expect(collect($o['avisos'])->pluck('codigo')->sort()->values()->all())->toBe(['pesaje_antiguo', 'retiro_vigente']);
    $this->postJson("{$this->base}/ofertas/{$o['id']}/publicar")->assertStatus(422)->assertJsonPath('code', 'requiere_confirmacion');
    $this->postJson("{$this->base}/ofertas/{$o['id']}/publicar", ['confirmar_avisos' => true])->assertOk();
});

it('compromisos: la misma hacienda no se publica en dos ofertas (409); vender todo deja la oferta vendida', function () {
    $o1 = $this->postJson("{$this->base}/ofertas/desde-lote", ['tipo' => 'gordo', 'lote_id' => $this->lote])->json('data');
    $this->postJson("{$this->base}/ofertas/{$o1['id']}/publicar")->assertOk();
    $this->getJson("{$this->base}/animales/{$this->animales[0]['animal_id']}")->assertJsonPath('data.compromiso', 'en_oferta');

    $o2 = $this->postJson("{$this->base}/ofertas/desde-lote", ['tipo' => 'gordo', 'animal_ids' => [$this->animales[0]['animal_id']]])->json('data');
    $this->postJson("{$this->base}/ofertas/{$o2['id']}/publicar")->assertStatus(409)->assertJsonPath('code', 'comprometido');

    $ids = array_column($this->animales, 'animal_id');
    sort($ids);
    $this->postJson("{$this->base}/eventos", ['tipo' => 'hacienda.egreso', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => $ids],
        'payload' => ['motivo' => 'venta', 'destino' => 'faena'], 'ocurrido_en' => now()->toDateString()])->assertCreated();
    $this->getJson("{$this->base}/ofertas/{$o1['id']}")->assertJsonPath('data.estado', 'vendida');
    $this->getJson("{$this->base}/animales/{$this->animales[0]['animal_id']}")->assertJsonPath('data.compromiso', 'vendido');
});

it('estados: reservar, retirar con motivo; una retirada no es pública; republicar = versión 2', function () {
    $o = $this->postJson("{$this->base}/ofertas/desde-lote", ['tipo' => 'gordo', 'lote_id' => $this->lote])->json('data');
    $this->postJson("{$this->base}/ofertas/{$o['id']}/publicar")->assertOk();
    $this->postJson("{$this->base}/ofertas/{$o['id']}/publicar")->assertOk()->assertJsonPath('data.publicacion_vigente', 2);
    $this->postJson("{$this->base}/ofertas/{$o['id']}/estado", ['estado' => 'reservada'])->assertOk();
    $this->getJson("/api/v1/public/ofertas/{$o['codigo_publico']}")->assertOk()->assertJsonPath('data.estado', 'reservada')->assertJsonPath('data.version', 2);
    $this->postJson("{$this->base}/ofertas/{$o['id']}/estado", ['estado' => 'retirada'])->assertStatus(422);
    $this->postJson("{$this->base}/ofertas/{$o['id']}/estado", ['estado' => 'retirada', 'motivo' => 'Se vendió por fuera'])->assertOk();
    $this->getJson("/api/v1/public/ofertas/{$o['codigo_publico']}")->assertNotFound();
    $this->getJson("{$this->base}/ofertas?estado=retirada")->assertJsonCount(1, 'data');
});

it('PDF de la ficha vía Gotenberg (con QR de verificación)', function () {
    Http::fake(['*/forms/chromium/convert/html' => Http::response('%PDF-1.7 fake', 200)]);
    $o = $this->postJson("{$this->base}/ofertas/desde-lote", ['tipo' => 'gordo', 'lote_id' => $this->lote])->json('data');
    $this->postJson("{$this->base}/ofertas/{$o['id']}/publicar")->assertOk();

    $this->get("/api/v1/public/ofertas/{$o['codigo_publico']}/pdf?locale=en")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    Http::assertSent(fn ($r) => str_contains($r->body(), 'Verify the integrity') && str_contains($r->body(), 'data:image/svg+xml;base64'));
});

it('permisos: asesor ve pero no gestiona (403), encargado no ve (403), otro establecimiento 404; 422 validación', function () {
    $o = $this->postJson("{$this->base}/ofertas", ['tipo' => 'gordo', 'especie' => 'BUB', 'cantidad' => 10, 'condiciones' => ['precio_kg' => '12,5']])->assertStatus(422);
    $o = $this->postJson("{$this->base}/ofertas", ['tipo' => 'gordo', 'lote_id' => $this->lote])->assertCreated()->json('data');

    Sanctum::actingAs(miembro($this->est, 'asesor'));
    $this->getJson("{$this->base}/ofertas/{$o['id']}")->assertOk();
    $this->postJson("{$this->base}/ofertas/{$o['id']}/publicar")->assertForbidden();
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson("{$this->base}/ofertas")->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/ofertas/{$o['id']}")->assertNotFound();
});
