<?php

use App\Domain\Integridad\CanonicalJson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->a = animalNuevo();
    $this->alta = $this->postJson("{$this->base}/eventos", altaBody([$this->a], '2026-03-01'))->json('data');
    $this->app['auth']->forgetGuards();
});

it('verifica la huella de un evento sin mostrar su contenido (verificar ≠ ver)', function () {
    $v = $this->getJson("/api/v1/public/verificaciones/{$this->alta['huella']}?locale=es")->assertOk()
        ->assertJsonPath('data.tipo', 'evento')->assertJsonPath('data.confianza', 'declarado')
        ->assertJsonPath('data.autor', ['nombre' => null, 'rol' => 'encargado'])
        ->assertJsonPath('data.establecimiento', null) // perfil no publicado
        ->assertJsonPath('data.sobre', null)->assertJsonPath('data.es_vigente', true)->assertJsonPath('data.versiones', 1)
        ->assertJsonPath('data.integridad.seq', 1)
        ->assertJsonPath('data.leyenda', fn ($l) => str_contains($l, 'No certifica'))->json('data');

    expect(CanonicalJson::eslabon($v['integridad']['eslabon_anterior'], $v['huella']))->toBe($v['integridad']['eslabon'])
        ->and(json_encode($v))->not->toContain($this->a['animal_id']);

    $this->get("/api/v1/public/verificaciones/{$this->alta['huella']}/prueba")->assertOk()->assertJsonPath('formula_eslabon', 'sha256(eslabon_anterior + "\n" + huella)');
});

it('una versión corregida deja de ser vigente; el sobre se publica solo para registros públicos (oferta)', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $this->postJson("{$this->base}/eventos/{$this->alta['id']}/correcciones", ['motivo' => 'Raza', 'payload' => ['motivo' => 'inventario_inicial', 'animales' => [[...$this->a, 'raza' => 'BUB_MURRAH']]]])->assertCreated();
    $o = $this->postJson("{$this->base}/ofertas", ['tipo' => 'cria', 'animal_ids' => [$this->a['animal_id']]])->json('data');
    $this->postJson("{$this->base}/ofertas/{$o['id']}/publicar", ['confirmar_avisos' => true])->assertOk();
    $huellaOferta = DB::table('oferta_publicaciones')->value('hash');
    $this->app['auth']->forgetGuards();

    $this->getJson("/api/v1/public/verificaciones/{$this->alta['huella']}")->assertJsonPath('data.es_vigente', false)->assertJsonPath('data.versiones', 2);
    $v = $this->getJson("/api/v1/public/verificaciones/{$huellaOferta}?locale=en")->assertOk()
        ->assertJsonPath('data.tipo', 'oferta_publicacion')->assertJsonPath('data.establecimiento.nombre', 'La Esperanza')->json('data');
    expect(CanonicalJson::huella(CanonicalJson::decode($v['sobre'])))->toBe($huellaOferta);
});

it('404 para huellas inexistentes o mal formadas', function () {
    $this->getJson('/api/v1/public/verificaciones/'.str_repeat('a', 64))->assertNotFound();
    $this->getJson('/api/v1/public/verificaciones/xyz')->assertNotFound();
});

it('verificar un archivo: por SHA-256 calculado en el navegador o subiéndolo (no se guarda)', function () {
    $this->getJson('/api/v1/public/verificaciones/archivo/'.hash('sha256', 'pagina-1').'?locale=es')->assertOk()
        ->assertJsonPath('data.coincide', false)->assertJsonPath('data.mensaje', fn ($m) => str_starts_with($m, 'No coincide'));
    $this->post('/api/v1/public/verificaciones/archivo', ['archivo' => UploadedFile::fake()->createWithContent('x.pdf', 'abc')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.coincide', false);
    expect(DB::table('archivos')->count())->toBe(0);
    $this->getJson('/api/v1/public/verificaciones/archivo/NOHEX')->assertStatus(422);
});
