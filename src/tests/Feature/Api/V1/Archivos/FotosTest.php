<?php

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Documentos\Fotos;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake();
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs($this->dueno = duenoDe($this->est));
    $this->subir = function (string $nombre): string {
        $sha = app(GuardarArchivo::class)->handle((string) file_get_contents(database_path("seeders/media/{$nombre}.webp")), 'image/webp', $this->dueno);
        DB::table('archivos')->where('sha256', $sha)->update(['alt' => json_encode(['es' => 'Foto de prueba', 'en' => 'Test photo'])]);

        return $sha;
    };
});

it('perfil publicado: Foto {url, alt, ancho, alto} y la URL pública sirve image/webp inmutable sin cuenta; no publicado → 404', function () {
    $sha = ($this->subir)('est-el-timbo');
    $p = $this->getJson("{$this->base}/perfil")->json('data');
    $this->patchJson("{$this->base}/perfil", ['row_version' => $p['row_version'], 'slug' => 'el-timbo', 'en_directorio' => true, 'fotos' => [$sha]])->assertOk()
        ->assertJsonPath('data.fotos.0.sha256', $sha)->assertJsonPath('data.fotos.0.ancho', 1536)->assertJsonPath('data.fotos.0.alto', 1024)
        ->assertJsonPath('data.fotos.0.url', fn ($u) => str_contains($u, '/api/v1/fotos/') && str_contains($u, 'signature='));

    $publica = Fotos::urlPublica($sha);
    $this->getJson(parse_url($publica, PHP_URL_PATH))->assertNotFound(); // perfil todavía sin publicar

    $this->postJson("{$this->base}/perfil/preview/aceptar")->assertOk();
    $this->postJson("{$this->base}/perfil/publicar")->assertOk();
    $this->app['auth']->forgetGuards();
    $r = $this->get(parse_url($publica, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('ETag', '"'.$sha.'"');
    expect($r->headers->get('Cache-Control'))->toContain('immutable')->toContain('max-age=31536000')
        ->and(hash('sha256', $r->getContent()))->toBe($sha);
    $this->get(parse_url($publica, PHP_URL_PATH), ['If-None-Match' => '"'.$sha.'"'])->assertStatus(304);

    $this->getJson('/api/v1/public/establecimientos/el-timbo?locale=en')->assertOk()
        ->assertJsonPath('data.secciones.portada.fotos.0', ['sha256' => $sha, 'url' => $publica, 'alt' => 'Test photo', 'ancho' => 1536, 'alto' => 1024]);
    $this->getJson('/api/v1/public/directorio?locale=es')->assertOk()->assertJsonPath('data.0.foto_url', $publica)->assertJsonPath('data.0.foto.alt', 'Foto de prueba');

    // Un hash que existe pero nadie publicado referencia → 404 (no se filtra existencia).
    $otro = ($this->subir)('est-la-paloma');
    $this->get("/api/v1/public/fotos/{$otro}")->assertNotFound();
    $this->get('/api/v1/public/fotos/'.str_repeat('a', 64))->assertNotFound();
});

it('oferta: la foto sale congelada en la publicación; retirada → la URL pública da 404', function () {
    $lote = crearLote($this->est, 'BUB', false, 'Bubillos');
    $a = animalNuevo(['sexo' => 'M', 'categoria' => 'BUB_BUBILLO', 'castrado' => true, 'lote_id' => $lote]);
    $this->postJson("{$this->base}/eventos", altaBody([$a]))->assertCreated();
    $sha = ($this->subir)('of-bubillos-mediterraneos');
    $o = $this->postJson("{$this->base}/ofertas/desde-lote", ['tipo' => 'gordo', 'lote_id' => $lote])->assertCreated()->json('data');
    $this->patchJson("{$this->base}/ofertas/{$o['id']}", ['row_version' => 1, 'fotos' => [str_repeat('b', 64)]])->assertStatus(422); // archivo inexistente
    $this->patchJson("{$this->base}/ofertas/{$o['id']}", ['row_version' => 1, 'fotos' => [$sha]])->assertOk()->assertJsonPath('data.fotos.0.sha256', $sha);
    $this->get("/api/v1/public/fotos/{$sha}")->assertNotFound(); // borrador
    $this->postJson("{$this->base}/ofertas/{$o['id']}/publicar", ['confirmar_avisos' => true])->assertOk();

    $publica = Fotos::urlPublica($sha);
    $this->getJson("/api/v1/public/ofertas/{$o['codigo_publico']}")->assertOk()
        ->assertJsonPath('data.contenido.fotos.0.url', $publica)->assertJsonPath('data.contenido.fotos.0.ancho', 1536);
    expect(DB::table('oferta_publicaciones')->where('oferta_id', $o['id'])->value('contenido'))->toContain($sha); // el hash queda en lo hasheado
    $this->get("/api/v1/public/fotos/{$sha}")->assertOk()->assertHeader('Content-Type', 'image/webp');

    $this->postJson("{$this->base}/ofertas/{$o['id']}/estado", ['estado' => 'retirada', 'motivo' => 'Vendida por fuera'])->assertOk();
    $this->get("/api/v1/public/fotos/{$sha}")->assertNotFound();
});

it('privadas (animal, usuario): URL firmada; sin firma o adulterada → 404; ruta pública → 404; descarga autenticada sin token → 401', function () {
    $sha = ($this->subir)('an-bufala-murrah');
    $a = animalNuevo(['foto' => $sha]);
    $this->postJson("{$this->base}/eventos", altaBody([$a]))->assertCreated();
    $url = $this->getJson("{$this->base}/animales/{$a['animal_id']}")->assertOk()->assertJsonPath('data.foto.sha256', $sha)->json('data.foto_url');
    $this->getJson("{$this->base}/animales")->assertJsonPath('data.0.foto_url', $url); // estable dentro de la hora

    $this->dueno->forceFill(['foto_sha256' => ($this->subir)('per-productor')])->save();
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.usuario.foto.ancho', 800)
        ->assertJsonPath('data.usuario.foto_url', fn ($u) => str_contains($u, 'signature='));

    $this->app['auth']->forgetGuards();
    $ruta = substr($url, strlen(rtrim(config('app.url'), '/')));
    $r = $this->get($ruta)->assertOk()->assertHeader('Content-Type', 'image/webp');
    expect($r->headers->get('Cache-Control'))->toContain('private');
    $this->get(strtok($ruta, '?'))->assertNotFound();
    $this->get(str_replace('signature=', 'signature=0', $ruta))->assertNotFound();
    $this->get("/api/v1/public/fotos/{$sha}")->assertNotFound();
    $this->getJson("/api/v1/archivos/{$sha}")->assertUnauthorized();

    $this->travel(3)->hours();
    $this->get($ruta)->assertNotFound(); // vencida
});

it('guardar fotos: 422 hash inexistente; 403 rol sin permiso de perfil; 404 otro establecimiento; profesional con foto pública', function () {
    $sha = ($this->subir)('per-veterinaria');
    $p = $this->getJson("{$this->base}/perfil")->json('data');
    $this->patchJson("{$this->base}/perfil", ['row_version' => $p['row_version'], 'fotos' => [str_repeat('c', 64)]])->assertStatus(422)->assertJsonValidationErrors('fotos.0');

    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->patchJson("{$this->base}/perfil", ['row_version' => $p['row_version'], 'fotos' => [$sha]])->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->patchJson("{$this->base}/perfil", ['row_version' => $p['row_version'], 'fotos' => [$sha]])->assertNotFound();

    $vet = User::factory()->create(['email_verificado_en' => now()]);
    Sanctum::actingAs($vet);
    $this->putJson('/api/v1/me/profesional', ['slug' => 'dra-vet', 'nombre_publico' => 'Dra. Vet', 'profesion' => 'veterinario', 'publico' => true, 'foto_sha256' => str_repeat('d', 64)])
        ->assertStatus(422);
    $this->putJson('/api/v1/me/profesional', ['slug' => 'dra-vet', 'nombre_publico' => 'Dra. Vet', 'profesion' => 'veterinario', 'publico' => true, 'foto_sha256' => $sha])
        ->assertOk()->assertJsonPath('data.foto.sha256', $sha);
    $this->getJson('/api/v1/public/profesionales/dra-vet')->assertOk()->assertJsonPath('data.foto_url', Fotos::urlPublica($sha))->assertJsonPath('data.foto.alto', 800);
    $this->get("/api/v1/public/fotos/{$sha}")->assertOk();
});
