<?php

use App\Domain\Cuentas\Models\Dispositivo;
use App\Domain\Cuentas\Notifications\RestablecerPasswordNotification;
use App\Domain\Cuentas\Notifications\VerificarEmailNotification;
use App\Domain\Cuentas\TokenVerificacionEmail;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Notification::fake());

it('GET /auth/me devuelve usuario y membresías', function () {
    $u = User::factory()->create();
    Sanctum::actingAs($u);

    $this->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.usuario.id', $u->id)
        ->assertJsonPath('data.usuario.email_verificado', true)
        ->assertJsonPath('data.comprador', null)
        ->assertJsonStructure(['data' => ['usuario', 'membresias', 'comprador', 'profesional', 'organizaciones']]);
});

it('PATCH /auth/me edita nombre, idioma y país; valida idioma', function () {
    Sanctum::actingAs($u = User::factory()->create());

    $this->patchJson('/api/v1/auth/me', ['nombre' => 'Nuevo', 'idioma' => 'pt', 'pais' => 'BR'])
        ->assertOk()->assertJsonPath('data.nombre', 'Nuevo')->assertJsonPath('data.idioma', 'pt');

    $this->patchJson('/api/v1/auth/me', ['idioma' => 'fr'])->assertStatus(422)->assertJsonValidationErrors(['idioma']);
});

it('verifica el email con el token del mail', function () {
    $u = User::factory()->unverified()->create();
    $token = TokenVerificacionEmail::generar($u);

    $this->postJson('/api/v1/auth/email/verificar', ['token' => $token])->assertNoContent();
    expect($u->fresh()->emailVerificado())->toBeTrue();
});

it('rechaza token de verificación adulterado, vencido o de otro email', function () {
    $u = User::factory()->unverified()->create();
    $token = TokenVerificacionEmail::generar($u);

    $this->postJson('/api/v1/auth/email/verificar', ['token' => $token.'x'])->assertStatus(422);

    $u->forceFill(['email' => 'otro@example.com'])->save();
    $this->postJson('/api/v1/auth/email/verificar', ['token' => $token])->assertStatus(422);

    $token2 = TokenVerificacionEmail::generar($u);
    $this->travel(49)->hours();
    $this->postJson('/api/v1/auth/email/verificar', ['token' => $token2])->assertStatus(422)->assertJsonPath('code', 'validacion');
    $this->postJson('/api/v1/auth/email/verificar', [])->assertStatus(422)->assertJsonValidationErrors(['token']);
});

it('reenvía la verificación al usuario logueado', function () {
    Sanctum::actingAs($u = User::factory()->unverified()->create());

    $this->postJson('/api/v1/auth/email/reenviar')->assertNoContent();
    Notification::assertSentTo($u, VerificarEmailNotification::class);
});

it('olvidé: 204 siempre; restablecer cambia la clave y revoca todos los tokens', function () {
    $u = User::factory()->create(['email' => 'a@example.com']);
    $u->createToken('web', ['web']);
    $u->createToken('web', ['web']);

    $this->postJson('/api/v1/auth/password/olvide', ['email' => 'noexiste@example.com'])->assertNoContent();
    Notification::assertNothingSent();

    $this->postJson('/api/v1/auth/password/olvide', ['email' => 'a@example.com'])->assertNoContent();

    $token = null;
    Notification::assertSentTo($u, RestablecerPasswordNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    $this->postJson('/api/v1/auth/password/restablecer', ['token' => $token, 'password' => 'clave-nueva-1'])->assertNoContent();
    expect($u->tokens()->count())->toBe(0);

    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'clave-nueva-1', 'cliente' => 'web'])->assertOk();

    // el token no se puede reutilizar
    $this->postJson('/api/v1/auth/password/restablecer', ['token' => $token, 'password' => 'otra-clave-2'])->assertStatus(422);
});

it('olvidé valida que venga email o teléfono', function () {
    $this->postJson('/api/v1/auth/password/olvide', [])->assertStatus(422)->assertJsonValidationErrors(['email', 'telefono']);
});

it('actualiza y revoca dispositivos propios; 404 los ajenos', function () {
    $u = User::factory()->create(['email' => 'a@example.com', 'password' => 'secreto-largo']);
    $id = (string) Str::uuid7();
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secreto-largo', 'cliente' => 'app', 'dispositivo' => ['id' => $id, 'plataforma' => 'android']])
        ->json('data.token');

    $this->withToken($token)->putJson("/api/v1/auth/dispositivos/{$id}", ['id' => $id, 'plataforma' => 'android', 'fcm_token' => 'abc', 'app_version' => '1.2.0'])
        ->assertOk()->assertJsonPath('data.fcm_token', 'abc')->assertJsonPath('data.app_version', '1.2.0');

    $otro = User::factory()->create();
    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($otro);
    $this->putJson("/api/v1/auth/dispositivos/{$id}", ['plataforma' => 'ios'])->assertNotFound()->assertJsonPath('code', 'no_encontrado');
    $this->postJson("/api/v1/auth/dispositivos/{$id}/revocar")->assertNotFound();

    Sanctum::actingAs($u);
    $this->postJson("/api/v1/auth/dispositivos/{$id}/revocar")->assertNoContent();
    expect(Dispositivo::query()->find($id)->revocado_en)->not->toBeNull()
        ->and($u->tokens()->count())->toBe(0);
});
