<?php

use App\Domain\Cuentas\Models\Dispositivo;
use App\Domain\Cuentas\Notifications\VerificarEmailNotification;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(fn () => Notification::fake());

it('registra con email, devuelve token y manda verificación', function () {
    $res = $this->postJson('/api/v1/auth/register', [
        'nombre' => 'Ramón Gómez',
        'email' => 'ramon@example.com',
        'password' => 'secreto-largo',
        'cliente' => 'web',
    ])->assertCreated()
        ->assertJsonPath('data.usuario.nombre', 'Ramón Gómez')
        ->assertJsonPath('data.usuario.email_verificado', false)
        ->assertJsonPath('data.usuario.idioma', 'es')
        ->assertJsonPath('data.membresias', []);

    expect($res->json('data.token'))->toBeString()
        ->and($res->json('data.usuario.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');

    $usuario = User::query()->where('email', 'ramon@example.com')->sole();
    Notification::assertSentTo($usuario, VerificarEmailNotification::class);

    $this->withToken($res->json('data.token'))->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.usuario.id', $usuario->id);
});

it('registra con teléfono sin email (sin verificación)', function () {
    $this->postJson('/api/v1/auth/register', [
        'nombre' => 'Encargado',
        'telefono' => '+5493794123456',
        'password' => 'secreto-largo',
        'cliente' => 'app',
        'dispositivo' => ['id' => (string) Str::uuid7(), 'plataforma' => 'android', 'nombre' => 'Moto G'],
    ])->assertCreated()->assertJsonPath('data.usuario.telefono', '+5493794123456');

    Notification::assertNothingSent();
    expect(Dispositivo::query()->count())->toBe(1);
});

it('valida registro: falta email y teléfono, password corta, email repetido', function () {
    User::factory()->create(['email' => 'ya@example.com']);

    $this->postJson('/api/v1/auth/register', ['nombre' => 'X', 'password' => 'corta'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validacion')
        ->assertJsonValidationErrors(['email', 'telefono', 'password']);

    $this->postJson('/api/v1/auth/register', ['nombre' => 'X', 'email' => 'YA@example.com', 'password' => 'secreto-largo'])
        ->assertStatus(422)->assertJsonValidationErrors(['email']);

    $this->postJson('/api/v1/auth/register', ['nombre' => 'X', 'telefono' => '3794123456', 'password' => 'secreto-largo'])
        ->assertStatus(422)->assertJsonValidationErrors(['telefono']);
});

it('la app exige dispositivo', function () {
    $this->postJson('/api/v1/auth/register', ['nombre' => 'X', 'email' => 'a@example.com', 'password' => 'secreto-largo', 'cliente' => 'app'])
        ->assertStatus(422)->assertJsonValidationErrors(['dispositivo']);
});

it('login web por email (case-insensitive) y por teléfono', function () {
    User::factory()->create(['email' => 'Ramon@Example.com', 'password' => 'secreto-largo']);
    User::factory()->conTelefono('+5493794000001')->create(['password' => 'otra-clave-1']);

    $this->postJson('/api/v1/auth/login', ['email' => 'ramon@example.com', 'password' => 'secreto-largo', 'cliente' => 'web'])
        ->assertOk()->assertJsonStructure(['data' => ['token', 'usuario' => ['id', 'nombre'], 'membresias']]);

    $this->postJson('/api/v1/auth/login', ['telefono' => '+5493794000001', 'password' => 'otra-clave-1', 'cliente' => 'web'])
        ->assertOk();
});

it('el token web vence a los 30 días y lleva ability web', function () {
    User::factory()->create(['email' => 'a@example.com', 'password' => 'secreto-largo']);
    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secreto-largo', 'cliente' => 'web'])->assertOk();

    $token = User::query()->sole()->tokens()->sole();
    expect($token->abilities)->toBe(['web'])
        ->and($token->expires_at->diffInDays(now(), true))->toBeGreaterThan(29);
});

it('credenciales incorrectas → 422 sin revelar si existe la cuenta', function () {
    User::factory()->create(['email' => 'a@example.com', 'password' => 'secreto-largo']);

    $mal = $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'incorrecta', 'cliente' => 'web'])
        ->assertStatus(422)->assertJsonPath('code', 'validacion');
    $noExiste = $this->postJson('/api/v1/auth/login', ['email' => 'zz@example.com', 'password' => 'incorrecta', 'cliente' => 'web'])
        ->assertStatus(422);

    expect($mal->json('message'))->toBe($noExiste->json('message'));
});

it('cuenta bloqueada no puede entrar', function () {
    User::factory()->create(['email' => 'a@example.com', 'password' => 'secreto-largo', 'bloqueado_en' => now()]);

    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secreto-largo', 'cliente' => 'web'])
        ->assertForbidden()->assertJsonPath('code', 'sin_permiso');
});

it('login app: un token por dispositivo (re-login revoca el anterior)', function () {
    $usuario = User::factory()->create(['email' => 'a@example.com', 'password' => 'secreto-largo']);
    $disp = ['id' => (string) Str::uuid7(), 'plataforma' => 'android'];
    $body = ['email' => 'a@example.com', 'password' => 'secreto-largo', 'cliente' => 'app', 'dispositivo' => $disp];

    $t1 = $this->postJson('/api/v1/auth/login', $body)->assertOk()->json('data.token');
    $t2 = $this->postJson('/api/v1/auth/login', $body)->assertOk()->json('data.token');

    expect($usuario->tokens()->count())->toBe(1)
        ->and($usuario->tokens()->sole()->abilities)->toBe(['app'])
        ->and($usuario->tokens()->sole()->expires_at)->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->withToken($t1)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'no_autenticado');
    $this->app['auth']->forgetGuards();
    $this->withToken($t2)->getJson('/api/v1/auth/me')->assertOk();
});

it('teléfono compartido: el dispositivo pasa al nuevo usuario y revoca el token anterior', function () {
    $a = User::factory()->create(['email' => 'a@example.com', 'password' => 'secreto-largo']);
    $b = User::factory()->create(['email' => 'b@example.com', 'password' => 'secreto-largo']);
    $disp = ['id' => (string) Str::uuid7(), 'plataforma' => 'android'];

    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secreto-largo', 'cliente' => 'app', 'dispositivo' => $disp])->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => 'b@example.com', 'password' => 'secreto-largo', 'cliente' => 'app', 'dispositivo' => $disp])->assertOk();

    expect($a->tokens()->count())->toBe(0)
        ->and($b->tokens()->count())->toBe(1)
        ->and(Dispositivo::query()->find($disp['id'])->usuario_id)->toBe($b->id);
});

it('logout revoca solo el token actual', function () {
    $usuario = User::factory()->create();
    $t1 = $usuario->createToken('web', ['web'])->plainTextToken;
    $t2 = $usuario->createToken('web', ['web'])->plainTextToken;

    $this->withToken($t1)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($usuario->tokens()->count())->toBe(1);
    $this->app['auth']->forgetGuards();
    $this->withToken($t1)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($t2)->getJson('/api/v1/auth/me')->assertOk();
});

it('rate limit de login: 5 por minuto por IP', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'x', 'cliente' => 'web'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'x', 'cliente' => 'web'])
        ->assertStatus(429)->assertJsonPath('code', 'demasiados_intentos')->assertHeader('Retry-After');
});

it('los errores llevan request_id y header X-Request-Id', function () {
    $res = $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    expect($res->json('request_id'))->toBeString()->not->toBeEmpty()
        ->and($res->headers->get('X-Request-Id'))->toBe($res->json('request_id'));
});
