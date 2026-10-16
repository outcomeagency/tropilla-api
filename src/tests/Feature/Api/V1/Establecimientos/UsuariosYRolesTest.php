<?php

use App\Domain\Establecimientos\Models\Membresia;
use App\Domain\Establecimientos\Notifications\InvitacionNotification;
use App\Models\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();
    $this->est = crearEstablecimiento();
    $this->dueno = duenoDe($this->est);
});

it('el dueño invita por email: mail con link, url en la respuesta, token no guardado en claro', function () {
    Sanctum::actingAs($this->dueno);

    $res = $this->postJson("/api/v1/establecimientos/{$this->est->id}/invitaciones", ['email' => 'vet@example.com', 'rol' => 'asesor'])
        ->assertCreated()->assertJsonPath('data.rol', 'asesor')->assertJsonPath('data.email', 'vet@example.com');

    $url = $res->json('data.url');
    expect($url)->toContain('/invitacion/');
    $token = basename($url);
    expect(DB::table('invitaciones')->value('token_hash'))->toBe(hash('sha256', $token));
    Notification::assertSentTo(new AnonymousNotifiable, InvitacionNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'vet@example.com');

    $this->getJson("/api/v1/establecimientos/{$this->est->id}/invitaciones")->assertOk()->assertJsonPath('data.0.url', null);
});

it('flujo completo: ver invitación pública → aceptar → miembro con abilities del rol', function () {
    Sanctum::actingAs($this->dueno);
    $token = basename($this->postJson("/api/v1/establecimientos/{$this->est->id}/invitaciones", ['telefono' => '+5493794555555', 'rol' => 'encargado'])->json('data.url'));

    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/public/invitaciones/{$token}")->assertOk()
        ->assertJsonPath('data.establecimiento_nombre', 'La Esperanza')
        ->assertJsonPath('data.rol', 'encargado')->assertJsonPath('data.vigente', true)
        ->assertJsonMissingPath('data.telefono');

    Sanctum::actingAs($nuevo = User::factory()->create());
    $this->postJson("/api/v1/invitaciones/{$token}/aceptar")->assertOk()
        ->assertJsonPath('data.rol', 'encargado')
        ->assertJsonPath('data.establecimiento_id', $this->est->id)
        ->assertJsonPath('data.abilities', fn ($a) => in_array('hacienda.crear', $a) && ! in_array('comercial.precios', $a));

    $this->postJson("/api/v1/invitaciones/{$token}/aceptar")->assertStatus(422);
    $this->getJson("/api/v1/public/invitaciones/{$token}")->assertOk()->assertJsonPath('data.vigente', false);
    $this->getJson('/api/v1/public/invitaciones/inexistente')->assertNotFound();
});

it('invitación vencida o revocada no se puede aceptar', function () {
    Sanctum::actingAs($this->dueno);
    $t1 = basename($this->postJson("/api/v1/establecimientos/{$this->est->id}/invitaciones", ['email' => 'a@example.com', 'rol' => 'lectura'])->json('data.url'));
    $r2 = $this->postJson("/api/v1/establecimientos/{$this->est->id}/invitaciones", ['email' => 'b@example.com', 'rol' => 'lectura']);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/invitaciones/{$r2->json('data.id')}/revocar")->assertNoContent();

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/invitaciones/'.basename($r2->json('data.url')).'/aceptar')->assertStatus(422);
    $this->travel(8)->days();
    $this->postJson("/api/v1/invitaciones/{$t1}/aceptar")->assertStatus(422);
});

it('valida invitación: rol dueño no, falta destino (422); 403 encargado; 404 otro establecimiento', function () {
    Sanctum::actingAs($this->dueno);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/invitaciones", ['email' => 'a@example.com', 'rol' => 'dueno'])
        ->assertStatus(422)->assertJsonValidationErrors(['rol']);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/invitaciones", ['rol' => 'admin'])
        ->assertStatus(422)->assertJsonValidationErrors(['email', 'telefono']);

    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/invitaciones", ['email' => 'a@example.com', 'rol' => 'lectura'])
        ->assertForbidden()->assertJsonPath('code', 'sin_permiso');

    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/invitaciones", ['email' => 'a@example.com', 'rol' => 'lectura'])
        ->assertNotFound();
});

it('lista membresías (cualquier rol) y cambia rol revocando + creando', function () {
    $enc = miembro($this->est, 'encargado');
    Sanctum::actingAs(miembro($this->est, 'lectura'));
    $this->getJson("/api/v1/establecimientos/{$this->est->id}/membresias")->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.rol', 'dueno');

    $m = Membresia::query()->where('usuario_id', $enc->id)->sole();
    Sanctum::actingAs($this->dueno);
    $nueva = $this->postJson("/api/v1/establecimientos/{$this->est->id}/membresias/{$m->id}/rol", ['rol' => 'admin'])
        ->assertOk()->assertJsonPath('data.rol', 'admin')->json('data.id');

    expect($nueva)->not->toBe($m->id)
        ->and($m->fresh()->estado)->toBe('revocada')
        ->and(Membresia::query()->where('usuario_id', $enc->id)->count())->toBe(2);

    $this->getJson("/api/v1/establecimientos/{$this->est->id}/membresias?estado=revocada")->assertOk()->assertJsonCount(1, 'data');
});

it('el admin no puede degradar ni revocar al dueño; nadie revoca al dueño', function () {
    $admin = miembro($this->est, 'admin');
    $mDueno = Membresia::query()->where('usuario_id', $this->dueno->id)->sole();

    Sanctum::actingAs($admin);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/membresias/{$mDueno->id}/rol", ['rol' => 'lectura'])
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/membresias/{$mDueno->id}/revocar", ['motivo' => 'chau'])
        ->assertStatus(422)->assertJsonPath('code', 'regla_bloqueante');
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/titularidad", ['usuario_id' => $admin->id, 'motivo' => 'x yz'])
        ->assertForbidden();
});

it('revocar acceso exige motivo y deja al usuario con 403 membresia_revocada', function () {
    $enc = miembro($this->est, 'encargado');
    $m = Membresia::query()->where('usuario_id', $enc->id)->sole();

    Sanctum::actingAs($this->dueno);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/membresias/{$m->id}/revocar", [])->assertStatus(422)->assertJsonValidationErrors(['motivo']);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/membresias/{$m->id}/revocar", ['motivo' => 'Dejó de trabajar'])->assertNoContent();

    Sanctum::actingAs($enc);
    $this->getJson("/api/v1/establecimientos/{$this->est->id}")->assertForbidden()->assertJsonPath('code', 'membresia_revocada');
});

it('membresía de otro establecimiento → 404 (scoped binding)', function () {
    $otro = crearEstablecimiento();
    $mOtro = Membresia::query()->where('establecimiento_id', $otro->id)->sole();

    Sanctum::actingAs($this->dueno);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/membresias/{$mOtro->id}/revocar", ['motivo' => 'xxx'])->assertNotFound();
});

it('transferir titularidad: solo el dueño, a un miembro; el saliente queda admin', function () {
    $admin = miembro($this->est, 'admin');
    $extraño = User::factory()->create();

    Sanctum::actingAs($this->dueno);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/titularidad", ['usuario_id' => $extraño->id, 'motivo' => 'Venta del campo'])
        ->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
    $this->postJson("/api/v1/establecimientos/{$this->est->id}/titularidad", ['usuario_id' => $admin->id, 'motivo' => 'Sucesión'])
        ->assertNoContent();

    expect(duenoDe($this->est)->id)->toBe($admin->id)
        ->and(Membresia::query()->activas()->where('usuario_id', $this->dueno->id)->sole()->rol->value)->toBe('admin')
        ->and(DB::table('auditoria')->where('accion', 'transferir_titularidad')->exists())->toBeTrue();
});

it('auditoría paginada por cursor; solo dueño/admin', function () {
    Sanctum::actingAs($this->dueno);
    foreach (range(1, 3) as $i) {
        $this->postJson("/api/v1/establecimientos/{$this->est->id}/invitaciones", ['email' => "u{$i}@example.com", 'rol' => 'lectura'])->assertCreated();
    }

    $p1 = $this->getJson("/api/v1/establecimientos/{$this->est->id}/auditoria?limit=2")->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('meta.limit', 2)->assertJsonPath('data.0.accion', 'invitar')
        ->assertJsonPath('data.0.usuario.id', $this->dueno->id);
    $cursor = $p1->json('meta.next_cursor');
    expect($cursor)->toBeString();
    $p2 = $this->getJson("/api/v1/establecimientos/{$this->est->id}/auditoria?limit=2&cursor={$cursor}")->assertOk();
    expect($p2->json('data.0.id'))->toBeLessThan($p1->json('data.1.id'));

    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson("/api/v1/establecimientos/{$this->est->id}/auditoria")->assertForbidden();
});
