<?php

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lista mis establecimientos con rol y abilities, sin ajenos, revocados ni archivados', function () {
    $u = User::factory()->create();
    $propio = crearEstablecimiento($u, ['nombre' => 'La Esperanza']);
    $asesorado = crearEstablecimiento(extra: ['nombre' => 'Santa Inés']);
    miembro($asesorado, 'asesor', $u);
    $revocado = crearEstablecimiento(extra: ['nombre' => 'El Ex']);
    miembro($revocado, 'encargado', $u);
    Membresia::query()->where('establecimiento_id', $revocado->id)->where('usuario_id', $u->id)->update(['estado' => 'revocada']);
    $archivado = crearEstablecimiento($u, ['nombre' => 'Archivado']);
    Establecimiento::query()->whereKey($archivado->id)->update(['archivado_en' => now()]);
    crearEstablecimiento(extra: ['nombre' => 'Ajeno']);

    Sanctum::actingAs($u);
    $data = $this->getJson('/api/v1/establecimientos')->assertOk()->json('data');

    expect(collect($data)->pluck('nombre')->sort()->values()->all())->toBe(['La Esperanza', 'Santa Inés'])
        ->and(collect($data)->firstWhere('nombre', 'Santa Inés')['mi_rol'])->toBe('asesor')
        ->and(collect($data)->firstWhere('nombre', 'Santa Inés')['renspa'])->toBeNull()
        ->and(collect($data)->firstWhere('nombre', 'La Esperanza')['abilities'])->toContain('usuarios.gestionar');
});

it('lista vacía para un usuario sin campos (onboarding pendiente); 401 sin token', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/establecimientos')->assertOk()->assertExactJson(['data' => []]);

    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', 'Bearer x')->getJson('/api/v1/establecimientos')->assertUnauthorized();
});
