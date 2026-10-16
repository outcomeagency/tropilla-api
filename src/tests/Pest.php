<?php

use App\Domain\Establecimientos\Actions\RealizarOnboarding;
use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Helpers de tenencia para los tests de feature.
*/

/** Datos válidos de EstablecimientoInput (Corrientes, Mercedes). */
function datosEstablecimiento(array $extra = []): array
{
    return array_replace_recursive([
        'nombre' => 'La Esperanza',
        'provincia_codigo' => '18',
        'departamento_codigo' => '18105',
        'localidad' => 'Mercedes',
        'actividades' => ['cria', 'recria'],
        'especies' => ['BOV', 'BUB'],
    ], $extra);
}

/** Crea productor + establecimiento por la Action real; `$dueno` queda dueño. */
function crearEstablecimiento(?User $dueno = null, array $extra = []): Establecimiento
{
    $dueno ??= User::factory()->create();

    return app(RealizarOnboarding::class)->handle($dueno, [
        'productor' => ['tipo' => 'sociedad', 'nombre' => 'Agropecuaria Don Ramón S.A.'],
        'establecimiento' => datosEstablecimiento($extra),
    ]);
}

/** Usuario nuevo con membresía activa del rol dado en el establecimiento. */
function miembro(Establecimiento $establecimiento, Rol|string $rol, ?User $usuario = null): User
{
    $usuario ??= User::factory()->create();
    Membresia::query()->create([
        'establecimiento_id' => $establecimiento->id,
        'usuario_id' => $usuario->id,
        'rol' => $rol instanceof Rol ? $rol : Rol::from($rol),
        'aceptada_en' => now(),
    ]);

    return $usuario;
}

function duenoDe(Establecimiento $establecimiento): User
{
    return Membresia::query()->activas()->where('establecimiento_id', $establecimiento->id)
        ->where('rol', 'dueno')->sole()->usuario;
}
