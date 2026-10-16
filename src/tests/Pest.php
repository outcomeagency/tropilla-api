<?php

use App\Domain\Establecimientos\Actions\RealizarOnboarding;
use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

/** Lote (maestro) directo en la base, para tests de eventos. */
function crearLote(Establecimiento $est, string $especie = 'BUB', bool $anonimo = true, string $nombre = 'Recría 2026'): string
{
    $id = (string) Str::uuid7();
    DB::table('lotes')->insert([
        'id' => $id, 'establecimiento_id' => $est->id, 'nombre' => $nombre, 'especie_codigo' => $especie, 'anonimo' => $anonimo,
        'row_version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function crearPotrero(Establecimiento $est, string $nombre = 'El Bajo'): string
{
    $id = (string) Str::uuid7();
    DB::table('potreros')->insert([
        'id' => $id, 'establecimiento_id' => $est->id, 'nombre' => $nombre, 'agua' => true, 'revolcadero' => false,
        'row_version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** animal_nuevo mínimo. */
function animalNuevo(array $extra = []): array
{
    return array_merge([
        'animal_id' => (string) Str::uuid7(), 'especie' => 'BUB', 'sexo' => 'H', 'categoria' => 'BUB_BUFALA',
    ], $extra);
}

/** Body de POST /eventos para un alta individual (web). */
function altaBody(array $animales, string $fecha = '2026-03-01'): array
{
    $ids = array_column($animales, 'animal_id');
    sort($ids);

    return ['tipo' => 'hacienda.alta', 'sujetos' => ['tipo' => 'animales', 'animal_ids' => $ids],
        'payload' => ['motivo' => 'inventario_inicial', 'animales' => $animales], 'ocurrido_en' => $fecha];
}
