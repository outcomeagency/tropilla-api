<?php

namespace App\Domain\Hacienda\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Models\Potrero;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alta y edición de potreros (TPA-14). La usan REST y el push de maestros de sync (TPA-43).
 * - Alta idempotente por `id` del cliente: si ya existe en el mismo establecimiento se devuelve tal cual.
 * - Edición con `row_version` (409 si está desactualizado) + auditoría + `cambios`.
 * - `poligono` (GeoJSON Polygon) → geography; si no hay superficie declarada se calcula del polígono.
 */
final class GuardarPotrero
{
    private const CAMPOS = ['nombre', 'superficie_ha', 'ambiente', 'recurso_forrajero', 'agua', 'revolcadero', 'notas'];

    public function __construct(
        private readonly RegistrarCambio $registrarCambio,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     * @return array{potrero: Potrero, creado: bool}
     */
    public function crear(Establecimiento $establecimiento, User $usuario, array $datos): array
    {
        return DB::transaction(function () use ($establecimiento, $usuario, $datos) {
            $cadena = Cadena::bloquearDeEstablecimiento($establecimiento->id);

            if (! empty($datos['id']) && ($existente = Potrero::query()->find($datos['id']))) {
                if ($existente->establecimiento_id !== $establecimiento->id) {
                    throw ApiException::validacion(__('El id ya está en uso.'), ['id' => [__('El id ya está en uso.')]]);
                }

                return ['potrero' => $existente, 'creado' => false];
            }

            $this->nombreLibre($establecimiento->id, $datos['nombre']);

            $potrero = new Potrero([...array_intersect_key($datos, array_flip(self::CAMPOS)), 'establecimiento_id' => $establecimiento->id]);
            if (! empty($datos['id'])) {
                $potrero->id = $datos['id'];
            }
            $potrero->save();
            $this->poligono($potrero, $datos);

            $this->registrarCambio->handle($establecimiento->id, 'potrero', $potrero->id, cadena: $cadena);
            $this->auditoria->handle($establecimiento->id, $usuario->id, 'potrero', $potrero->id, 'crear', null, $potrero->only(self::CAMPOS));

            return ['potrero' => $potrero->refresh(), 'creado' => true];
        });
    }

    /** @param array<string, mixed> $datos */
    public function editar(Potrero $potrero, User $usuario, array $datos): Potrero
    {
        return DB::transaction(function () use ($potrero, $usuario, $datos) {
            $cadena = Cadena::bloquearDeEstablecimiento($potrero->establecimiento_id);
            /** @var Potrero $p */
            $p = Potrero::query()->lockForUpdate()->findOrFail($potrero->id);

            if ((int) $datos['row_version'] !== $p->row_version) {
                throw ApiException::conflictoRowVersion(['row_version' => $p->row_version]);
            }
            if (isset($datos['nombre']) && mb_strtolower($datos['nombre']) !== mb_strtolower($p->nombre)) {
                $this->nombreLibre($p->establecimiento_id, $datos['nombre'], $p->id);
            }

            $antes = $p->only(self::CAMPOS);
            $p->fill(array_intersect_key($datos, array_flip(self::CAMPOS)));
            $cambiaPoligono = array_key_exists('poligono', $datos);
            if (! $p->isDirty() && ! $cambiaPoligono) {
                return $p;
            }
            $p->row_version++;
            $p->save();
            if ($cambiaPoligono) {
                $this->poligono($p, $datos);
            }

            $this->registrarCambio->handle($p->establecimiento_id, 'potrero', $p->id, cadena: $cadena);
            $this->auditoria->handle($p->establecimiento_id, $usuario->id, 'potrero', $p->id, 'editar', $antes, $p->only(self::CAMPOS));

            return $p->refresh();
        });
    }

    public function archivar(Potrero $potrero, User $usuario, string $motivo): Potrero
    {
        return DB::transaction(function () use ($potrero, $usuario, $motivo) {
            $cadena = Cadena::bloquearDeEstablecimiento($potrero->establecimiento_id);
            /** @var Potrero $p */
            $p = Potrero::query()->lockForUpdate()->findOrFail($potrero->id);
            if ($p->archivado_en !== null) {
                return $p;
            }

            $p->forceFill(['archivado_en' => now(), 'archivado_por' => $usuario->id, 'row_version' => $p->row_version + 1])->save();
            $this->registrarCambio->handle($p->establecimiento_id, 'potrero', $p->id, 'archivar', cadena: $cadena);
            $this->auditoria->handle($p->establecimiento_id, $usuario->id, 'potrero', $p->id, 'archivar', null, null, $motivo);

            return $p;
        });
    }

    private function nombreLibre(string $establecimientoId, string $nombre, ?string $excepto = null): void
    {
        $usado = Potrero::query()->where('establecimiento_id', $establecimientoId)->whereNull('archivado_en')
            ->whereRaw('lower(nombre) = lower(?)', [$nombre])
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))
            ->exists();
        if ($usado) {
            throw ApiException::validacion(__('Ya hay un potrero con ese nombre.'), ['nombre' => [__('Ya hay un potrero con ese nombre.')]]);
        }
    }

    /** @param array<string, mixed> $datos */
    private function poligono(Potrero $potrero, array $datos): void
    {
        if (! array_key_exists('poligono', $datos)) {
            return;
        }
        if ($datos['poligono'] === null) {
            DB::update('UPDATE potreros SET poligono = NULL WHERE id = ?', [$potrero->id]);

            return;
        }
        $geojson = json_encode($datos['poligono']);
        DB::update('UPDATE potreros SET poligono = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)::geography WHERE id = ?', [$geojson, $potrero->id]);
        if (($datos['superficie_ha'] ?? null) === null && $potrero->superficie_ha === null) {
            DB::update('UPDATE potreros SET superficie_ha = round((ST_Area(poligono) / 10000)::numeric, 2) WHERE id = ?', [$potrero->id]);
        }
    }
}
