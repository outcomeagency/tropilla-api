<?php

use App\Domain\Establecimientos\Models\Productor;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->est = crearEstablecimiento();
    $this->productor = Productor::query()->sole();
    $this->productor->asignarCuit('30-71234567-1');
    $this->productor->save();
});

it('el dueño ve el CUIT; el encargado no (null, clave presente)', function () {
    Sanctum::actingAs(duenoDe($this->est));
    $this->getJson("/api/v1/productores/{$this->productor->id}")->assertOk()->assertJsonPath('data.cuit', '30712345671');

    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->getJson("/api/v1/productores/{$this->productor->id}")->assertOk()
        ->assertJsonPath('data.cuit', null)->assertJsonPath('data.nombre', 'Agropecuaria Don Ramón S.A.');
});

it('404 para quien no tiene membresía en ningún establecimiento del productor', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v1/productores/{$this->productor->id}")->assertNotFound()->assertJsonPath('code', 'no_encontrado');
    $this->patchJson("/api/v1/productores/{$this->productor->id}", ['row_version' => 1, 'nombre' => 'X'])->assertNotFound();
    $this->getJson('/api/v1/productores/no-es-uuid')->assertNotFound();
});

it('PATCH con row_version: edita, incrementa y audita; 409 si está desactualizado', function () {
    Sanctum::actingAs(duenoDe($this->est));

    $this->patchJson("/api/v1/productores/{$this->productor->id}", ['row_version' => 1, 'nombre' => 'Don Ramón SRL', 'contacto' => ['whatsapp' => '+5493794111111']])
        ->assertOk()->assertJsonPath('data.nombre', 'Don Ramón SRL')->assertJsonPath('data.row_version', 2)
        ->assertJsonPath('data.contacto.whatsapp', '+5493794111111');

    $this->patchJson("/api/v1/productores/{$this->productor->id}", ['row_version' => 1, 'nombre' => 'Otro'])
        ->assertStatus(409)->assertJsonPath('code', 'conflicto_row_version');

    expect(DB::table('auditoria')->where('entidad', 'productor')->where('accion', 'editar')->count())->toBe(1);
});

it('403 para roles sin establecimiento.editar; 422 sin row_version', function () {
    Sanctum::actingAs(miembro($this->est, 'asesor'));
    $this->patchJson("/api/v1/productores/{$this->productor->id}", ['row_version' => 1, 'nombre' => 'X'])
        ->assertForbidden()->assertJsonPath('code', 'sin_permiso');

    Sanctum::actingAs(duenoDe($this->est));
    $this->patchJson("/api/v1/productores/{$this->productor->id}", ['nombre' => 'X'])
        ->assertStatus(422)->assertJsonValidationErrors(['row_version']);
});
