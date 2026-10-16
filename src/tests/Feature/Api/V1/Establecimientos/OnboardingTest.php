<?php

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Productor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function onboardingBody(array $extra = []): array
{
    return array_replace_recursive([
        'productor' => ['tipo' => 'sociedad', 'nombre' => 'Agropecuaria Don Ramón S.A.', 'cuit' => '30-71234567-1'],
        'establecimiento' => datosEstablecimiento([
            'renspa' => '18.105.0.00123/00',
            'superficie_ha' => '2450.5',
            'ubicacion' => ['lat' => '-29.1843', 'lon' => '-58.0754'],
            'instalaciones' => ['manga', 'balanza'],
        ]),
    ], $extra);
}

it('crea productor y establecimiento; el usuario queda dueño con todas las abilities', function () {
    Sanctum::actingAs($u = User::factory()->create());

    $res = $this->postJson('/api/v1/onboarding', onboardingBody())->assertCreated()
        ->assertJsonPath('data.nombre', 'La Esperanza')
        ->assertJsonPath('data.mi_rol', 'dueno')
        ->assertJsonPath('data.especies', ['BOV', 'BUB'])
        ->assertJsonPath('data.superficie_ha', '2450.5')
        ->assertJsonPath('data.renspa', '18.105.0.00123/00')
        ->assertJsonPath('data.ubicacion', ['lat' => '-29.1843', 'lon' => '-58.0754'])
        ->assertJsonPath('data.zonas_sanitarias.garrapata', 'erradicacion') // sugerida por Mercedes
        ->assertJsonPath('data.row_version', 1);

    expect($res->json('data.abilities'))->toContain('titularidad.transferir', 'comercial.precios');

    $est = Establecimiento::query()->sole();
    expect(DB::table('cadenas')->where('establecimiento_id', $est->id)->exists())->toBeTrue()
        ->and(DB::table('cambios')->where('establecimiento_id', $est->id)->pluck('seq')->all())->toBe([1, 2]);

    $this->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.membresias.0.establecimiento_id', $est->id)
        ->assertJsonPath('data.membresias.0.rol', 'dueno')
        ->assertJsonPath('data.membresias.0.productor_nombre', 'Agropecuaria Don Ramón S.A.');
});

it('guarda el CUIT cifrado y nunca en claro', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/onboarding', onboardingBody())->assertCreated();

    $crudo = DB::table('productores')->value('cuit_cifrado');
    expect($crudo)->not->toContain('30712345671')
        ->and(Productor::query()->sole()->cuit())->toBe('30712345671')
        ->and(DB::table('productores')->value('cuit_hash'))->toBe(Productor::hashCuit('30712345671'));
});

it('valida el onboarding (422)', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/onboarding', [])->assertStatus(422)
        ->assertJsonPath('code', 'validacion')
        ->assertJsonValidationErrors(['productor', 'establecimiento']);

    $this->postJson('/api/v1/onboarding', onboardingBody([
        'productor' => ['cuit' => '30-71234567-9', 'tipo' => 'cooperativa'],
        'establecimiento' => [
            'departamento_codigo' => '18999', 'especies' => ['OVI'], 'actividades' => ['pesca'],
            'superficie_ha' => '12.50', 'ubicacion' => ['lat' => '-95', 'lon' => '-58'],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors([
        'productor.cuit', 'productor.tipo', 'establecimiento.departamento_codigo', 'establecimiento.especies.0',
        'establecimiento.actividades.0', 'establecimiento.superficie_ha', 'establecimiento.ubicacion.lat',
    ]);
});

it('exige autenticación', function () {
    $this->postJson('/api/v1/onboarding', onboardingBody())->assertUnauthorized();
});

it('acepta provincias sin departamentos cargados validando el formato', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/onboarding', onboardingBody(['establecimiento' => ['provincia_codigo' => '22', 'departamento_codigo' => '22140']]))
        ->assertCreated()->assertJsonPath('data.zonas_sanitarias.garrapata', null);
    $this->postJson('/api/v1/onboarding', onboardingBody(['establecimiento' => ['provincia_codigo' => '22', 'departamento_codigo' => '18105']]))
        ->assertStatus(422)->assertJsonValidationErrors(['establecimiento.departamento_codigo']);
});
