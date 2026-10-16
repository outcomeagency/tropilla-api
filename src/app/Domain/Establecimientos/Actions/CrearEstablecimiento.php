<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Catalogos\Models\GeoDepartamento;
use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Domain\Establecimientos\Models\Productor;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Crea un establecimiento de un productor con su cadena de integridad, y deja al usuario como **dueño**.
 * Si no se declara garrapata, se sugiere la del departamento (functional/03 §4.4).
 */
final class CrearEstablecimiento
{
    public function __construct(
        private readonly RegistrarCambio $registrarCambio,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** @param array<string, mixed> $datos validado por ReglasEstablecimiento */
    public function handle(Productor $productor, User $usuario, array $datos): Establecimiento
    {
        return DB::transaction(function () use ($productor, $usuario, $datos) {
            $zonas = self::zonasConDefaults($datos['zonas_sanitarias'] ?? [], $datos['departamento_codigo']);

            $establecimiento = new Establecimiento([
                ...array_intersect_key($datos, array_flip([
                    'nombre', 'provincia_codigo', 'departamento_codigo', 'localidad', 'ubicacion', 'renspa',
                    'superficie_ha', 'actividades', 'especies', 'historia',
                ])),
                'instalaciones' => $datos['instalaciones'] ?? [],
                'zonas_sanitarias' => $zonas,
            ]);
            $establecimiento->productor()->associate($productor);
            $establecimiento->save();

            $cadena = Cadena::query()->create(['establecimiento_id' => $establecimiento->id]);
            $cadena = Cadena::bloquearDeEstablecimiento($establecimiento->id);

            $membresia = Membresia::query()->create([
                'establecimiento_id' => $establecimiento->id,
                'usuario_id' => $usuario->id,
                'rol' => Rol::Dueno,
                'aceptada_en' => now(),
            ]);

            $this->registrarCambio->handle($establecimiento->id, 'establecimiento', $establecimiento->id, cadena: $cadena);
            $this->registrarCambio->handle($establecimiento->id, 'membresia', $membresia->id, cadena: $cadena);
            $this->auditoria->handle($establecimiento->id, $usuario->id, 'establecimiento', $establecimiento->id, 'crear', null, [
                'nombre' => $establecimiento->nombre, 'productor_id' => $productor->id,
            ]);

            return $establecimiento->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $zonas
     * @return array{aftosa_zona: ?string, garrapata: ?string, rabia_endemica: bool, fasciola_endemica: bool}
     */
    public static function zonasConDefaults(array $zonas, string $departamento): array
    {
        $garrapata = $zonas['garrapata'] ?? null;
        if (! array_key_exists('garrapata', $zonas)) {
            $garrapata = GeoDepartamento::query()->find($departamento)?->garrapata_sugerida;
        }

        return [
            'aftosa_zona' => $zonas['aftosa_zona'] ?? null,
            'garrapata' => $garrapata,
            'rabia_endemica' => (bool) ($zonas['rabia_endemica'] ?? false),
            'fasciola_endemica' => (bool) ($zonas['fasciola_endemica'] ?? false),
        ];
    }
}
