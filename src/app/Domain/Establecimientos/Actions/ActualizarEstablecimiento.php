<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /establecimientos/{e} (TPA-13): datos generales, superficie, actividades, especies, instalaciones,
 * historia, zonas sanitarias declaradas y ubicación (privada). Concurrencia optimista por `row_version`,
 * auditoría antes/después y fila en `cambios` para que baje por sync.
 * La interdicción **no** es un campo: es el evento `establecimiento.interdiccion` (TPA-22).
 */
final class ActualizarEstablecimiento
{
    private const CAMPOS = [
        'nombre', 'provincia_codigo', 'departamento_codigo', 'localidad', 'ubicacion', 'renspa', 'superficie_ha',
        'actividades', 'especies', 'instalaciones', 'historia',
    ];

    public function __construct(
        private readonly RegistrarCambio $registrarCambio,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** @param array<string, mixed> $datos */
    public function handle(Establecimiento $establecimiento, User $usuario, array $datos): Establecimiento
    {
        return DB::transaction(function () use ($establecimiento, $usuario, $datos) {
            $cadena = Cadena::bloquearDeEstablecimiento($establecimiento->id);
            /** @var Establecimiento $e */
            $e = Establecimiento::query()->lockForUpdate()->findOrFail($establecimiento->id);

            if ((int) $datos['row_version'] !== $e->row_version) {
                throw ApiException::conflictoRowVersion(['row_version' => $e->row_version]);
            }

            $antes = $this->snapshot($e);
            $e->fill(array_intersect_key($datos, array_flip(self::CAMPOS)));
            if (array_key_exists('zonas_sanitarias', $datos)) {
                $e->zonas_sanitarias = [...CrearEstablecimiento::zonasConDefaults([], $e->departamento_codigo), ...$e->zonas_sanitarias, ...$datos['zonas_sanitarias']];
            }

            if (! $e->isDirty()) {
                return $e;
            }

            $e->row_version++;
            $e->save();
            $e->refresh();

            $this->registrarCambio->handle($e->id, 'establecimiento', $e->id, cadena: $cadena);
            $this->auditoria->handle($e->id, $usuario->id, 'establecimiento', $e->id, 'editar', $antes, $this->snapshot($e));

            return $e;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Establecimiento $e): array
    {
        $s = $e->only([...self::CAMPOS, 'zonas_sanitarias']);
        // la ubicación exacta es privada: la auditoría solo registra que cambió
        $s['ubicacion'] = $e->ubicacion === null ? null : '(definida)';

        return $s;
    }
}
