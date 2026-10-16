<?php

use App\Domain\Comercial\Notifications\NuevaConsultaNotification;
use App\Domain\Perfil\Services\Perfiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->est = crearEstablecimiento();
    Perfiles::asegurar($this->est);
    DB::table('perfiles_publicos')->where('establecimiento_id', $this->est->id)->update(['publicado' => true, 'preview_aceptada_en' => now(), 'slug' => 'la-esperanza']);
    $this->body = ['nombre' => 'Li Wei', 'empresa' => 'Shandong Meat', 'pais' => 'cn', 'email' => 'li@example.com', 'tipo_comprador' => 'exportador',
        'volumen' => '200 t/año', 'mensaje' => 'We are interested in buffalo.', 'establecimiento_slug' => 'la-esperanza', 'captcha_token' => 'tok'];
});

it('consulta sin cuenta: llega a la bandeja con el idioma y avisa por mail al dueño/admin', function () {
    $this->postJson('/api/v1/public/consultas', $this->body, ['Accept-Language' => 'en'])->assertCreated()
        ->assertJsonPath('data.recibida', true)->assertJsonPath('data.mensaje', fn ($m) => str_contains($m, 'sent to the producer'));

    $c = DB::table('consultas')->sole();
    expect($c)->idioma->toBe('en')->pais->toBe('CN')->estado->toBe('nueva')->origen->toBe('perfil')
        ->and($c->ip_hash)->toHaveLength(64);
    Notification::assertSentTo(duenoDe($this->est), NuevaConsultaNotification::class);
});

it('honeypot: se acepta en silencio y queda como spam, sin avisar', function () {
    $this->postJson('/api/v1/public/consultas', [...$this->body, 'website' => 'http://spam'])->assertCreated();
    expect(DB::table('consultas')->value('estado'))->toBe('spam');
    Notification::assertNothingSent();
});

it('captcha Turnstile inválido → 422 (con secreto configurado)', function () {
    config(['services.turnstile.secret' => 'x']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
    $this->postJson('/api/v1/public/consultas', $this->body)->assertStatus(422)->assertJsonValidationErrors(['captcha_token']);
});

it('rate limit 3 por hora por IP', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/public/consultas', $this->body)->assertCreated();
    }
    $this->postJson('/api/v1/public/consultas', $this->body)->assertStatus(429)->assertJsonPath('code', 'demasiados_intentos');
});

it('422 campos obligatorios; 404 perfil no publicado u oferta inexistente', function () {
    $this->postJson('/api/v1/public/consultas', [])->assertStatus(422)->assertJsonValidationErrors(['nombre', 'pais', 'tipo_comprador', 'mensaje', 'captcha_token', 'email']);
    $this->postJson('/api/v1/public/consultas', [...$this->body, 'establecimiento_slug' => 'no-existe'])->assertNotFound();
    $this->postJson('/api/v1/public/consultas', [...$this->body, 'establecimiento_slug' => null, 'oferta_codigo' => 'ZZZZZZZZZZ'])->assertNotFound();
});
