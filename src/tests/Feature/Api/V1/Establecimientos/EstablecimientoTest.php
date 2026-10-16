<?php

use App\Domain\Establecimientos\Models\Membresia;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('GET establecimiento: privados solo para dueño/admin', function () {
    $est = crearEstablecimiento(extra: ['renspa' => 'R-1', 'ubicacion' => ['lat' => '-29.1', 'lon' => '-58.07']]);

    Sanctum::actingAs(duenoDe($est));
    $this->getJson("/api/v1/establecimientos/{$est->id}")->assertOk()
        ->assertJsonPath('data.renspa', 'R-1')->assertJsonPath('data.ubicacion.lat', '-29.1');

    Sanctum::actingAs(miembro($est, 'lectura'));
    $this->getJson("/api/v1/establecimientos/{$est->id}")->assertOk()
        ->assertJsonPath('data.renspa', null)->assertJsonPath('data.ubicacion', null)
        ->assertJsonPath('data.mi_rol', 'lectura')->assertJsonPath('data.abilities', fn ($a) => ! in_array('hacienda.crear', $a));
});

it('otro establecimiento → 404; membresía revocada → 403 membresia_revocada', function () {
    $est = crearEstablecimiento();
    $otro = crearEstablecimiento();

    Sanctum::actingAs(duenoDe($otro));
    $this->getJson("/api/v1/establecimientos/{$est->id}")->assertNotFound()->assertJsonPath('code', 'no_encontrado');

    $ex = miembro($est, 'encargado');
    Membresia::query()->where('usuario_id', $ex->id)->update(['estado' => 'revocada', 'revocada_en' => now()]);
    Sanctum::actingAs($ex);
    $this->getJson("/api/v1/establecimientos/{$est->id}")->assertForbidden()->assertJsonPath('code', 'membresia_revocada');
});

it('POST /establecimientos: dueño/admin del productor agrega otro campo y queda dueño', function () {
    $est = crearEstablecimiento();
    Sanctum::actingAs($dueno = duenoDe($est));

    $this->postJson('/api/v1/establecimientos', datosEstablecimiento(['nombre' => 'El Junco', 'productor_id' => $est->productor_id]))
        ->assertCreated()->assertJsonPath('data.nombre', 'El Junco')->assertJsonPath('data.mi_rol', 'dueno')
        ->assertJsonPath('data.productor_id', $est->productor_id);

    expect(Membresia::query()->activas()->where('usuario_id', $dueno->id)->count())->toBe(2);
});

it('POST /establecimientos: 403 encargado, 422 productor ajeno o datos inválidos', function () {
    $est = crearEstablecimiento();
    $ajeno = crearEstablecimiento();

    Sanctum::actingAs(miembro($est, 'encargado'));
    $this->postJson('/api/v1/establecimientos', datosEstablecimiento(['productor_id' => $est->productor_id]))
        ->assertForbidden()->assertJsonPath('code', 'sin_permiso');

    Sanctum::actingAs(duenoDe($est));
    $this->postJson('/api/v1/establecimientos', datosEstablecimiento(['productor_id' => $ajeno->productor_id]))
        ->assertStatus(422)->assertJsonValidationErrors(['productor_id']);
    $this->postJson('/api/v1/establecimientos', ['productor_id' => $est->productor_id])
        ->assertStatus(422)->assertJsonValidationErrors(['nombre', 'especies', 'actividades']);
});

it('un usuario puede tener varios establecimientos de distintos productores', function () {
    $u = User::factory()->create();
    crearEstablecimiento($u);
    $b = crearEstablecimiento();
    miembro($b, 'asesor', $u);

    Sanctum::actingAs($u);
    expect($this->getJson('/api/v1/auth/me')->json('data.membresias'))->toHaveCount(2);
});
