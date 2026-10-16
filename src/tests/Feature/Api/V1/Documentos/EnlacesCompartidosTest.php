<?php

use App\Domain\Documentos\Actions\GuardarArchivo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    $this->travelTo('2026-10-01 15:00:00');
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $sha = app(GuardarArchivo::class)->handle('%PDF certificado', 'application/pdf', $this->dueno);
    $this->ref = ['sha256' => $sha, 'bytes' => 16, 'mime' => 'application/pdf'];
    $this->doc = $this->postJson("{$this->base}/documentos", ['tipo' => 'certificado_sanitario', 'titulo' => 'Libre de brucelosis', 'vence_en' => '2027-01-01',
        'emisor' => ['nombre' => 'Vet. Pérez'], 'archivos' => [$this->ref]])->assertCreated()->json('data');
});

it('crea un enlace que vence, sin publicar; el comprador lo abre sin cuenta con confianza y verificación; se registra el acceso', function () {
    $e = $this->postJson("{$this->base}/documentos/{$this->doc['id']}/enlaces", ['expira_en' => '2026-10-08T15:00:00.000Z'])->assertCreated()
        ->assertJsonPath('data.accesos', 0)->assertJsonPath('data.revocado_en', null)->assertJsonPath('data.aviso', null)->json('data');
    expect($e['url'])->toMatch('#/e/[A-Za-z0-9_-]{43}$#')
        ->and(DB::table('enlaces_compartidos')->value('token_hash'))->toBe(hash('sha256', basename($e['url'])));
    $this->getJson("{$this->base}/documentos/{$this->doc['id']}")->assertJsonPath('data.visibilidad', 'privado')->assertJsonPath('data.enlaces_activos', 1);

    $token = basename($e['url']);
    auth()->forgetGuards();
    $p = $this->getJson("/api/v1/public/enlaces/{$token}?locale=en")->assertOk()->assertHeader('X-Robots-Tag', 'noindex')
        ->assertJsonPath('data.documento.titulo', 'Libre de brucelosis')->assertJsonPath('data.documento.confianza', 'declarado')
        ->assertJsonPath('data.documento.emisor_declarado', 'Vet. Pérez')->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.archivos.0.estado', 'completo')->assertJsonPath('data.establecimiento.nombre', 'La Esperanza');
    expect($p->json('data.documento.verificacion_url'))->toContain('/v/')->and($p->json('data.archivos.0.url'))->not->toBeNull()
        ->and($p->json('data.leyenda'))->toContain('does not certify');
    expect(DB::table('enlace_accesos')->count())->toBe(1);

    Sanctum::actingAs($this->dueno);
    $this->getJson("{$this->base}/documentos/{$this->doc['id']}/enlaces")->assertJsonPath('data.0.accesos', 1)->assertJsonPath('data.0.url', null);

    // Vence.
    $this->travelTo('2026-10-08 15:00:01');
    $this->getJson("/api/v1/public/enlaces/{$token}")->assertStatus(410)->assertJsonPath('code', 'enlace_vencido');
});

it('revocar corta el acceso; enlace fijo a una versión; fiscal se comparte con aviso; validaciones', function () {
    $e = $this->postJson("{$this->base}/documentos/{$this->doc['id']}/enlaces", ['expira_en' => '2026-10-31T00:00:00.000Z', 'version' => 1])->assertCreated()->json('data');
    $this->postJson("{$this->base}/documentos/{$this->doc['id']}/versiones", ['accion' => 'correccion_metadatos', 'motivo' => 'Título', 'titulo' => 'Libre de brucelosis 2026'])->assertCreated();
    $this->getJson('/api/v1/public/enlaces/'.basename($e['url']))->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.documento.titulo', 'Libre de brucelosis')->assertJsonPath('data.documento.estado', 'reemplazado');

    $this->postJson("{$this->base}/documentos/{$this->doc['id']}/enlaces/{$e['id']}/revocar")->assertNoContent();
    $this->getJson('/api/v1/public/enlaces/'.basename($e['url']))->assertStatus(410);
    $this->getJson('/api/v1/public/enlaces/'.str_repeat('a', 43))->assertNotFound();

    $fact = $this->postJson("{$this->base}/documentos", ['tipo' => 'factura', 'titulo' => 'Factura', 'archivos' => [$this->ref]])->json('data.id');
    $this->postJson("{$this->base}/documentos/{$fact}/enlaces", ['expira_en' => '2026-10-02T00:00:00.000Z'])->assertCreated()
        ->assertJson(fn ($j) => $j->whereType('data.aviso', 'string')->etc());

    $this->postJson("{$this->base}/documentos/{$this->doc['id']}/enlaces", ['expira_en' => '2027-03-01T00:00:00.000Z'])->assertStatus(422)->assertJsonValidationErrors(['expira_en']);
    $this->postJson("{$this->base}/documentos/{$this->doc['id']}/enlaces", ['expira_en' => '2026-09-01T00:00:00.000Z'])->assertStatus(422);
    $this->postJson("{$this->base}/documentos/{$this->doc['id']}/enlaces", ['expira_en' => 'mañana'])->assertStatus(422);
    $this->postJson("{$this->base}/documentos/{$this->doc['id']}/enlaces", ['expira_en' => '2026-10-05T00:00:00.000Z', 'version' => 9])->assertStatus(422)->assertJsonValidationErrors(['version']);
});

it('solo dueño/admin comparten; 404 ajeno', function () {
    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson("{$this->base}/documentos/{$this->doc['id']}/enlaces")->assertForbidden();
    $this->postJson("{$this->base}/documentos/{$this->doc['id']}/enlaces", ['expira_en' => '2026-10-05T00:00:00.000Z'])->assertForbidden();
    Sanctum::actingAs(miembro($this->est, 'admin'));
    $this->postJson("{$this->base}/documentos/{$this->doc['id']}/enlaces", ['expira_en' => '2026-10-05T00:00:00.000Z'])->assertCreated();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/documentos/{$this->doc['id']}/enlaces")->assertNotFound();
});
