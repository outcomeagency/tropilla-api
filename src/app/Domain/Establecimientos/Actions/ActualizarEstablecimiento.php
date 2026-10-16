<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        'ficha_ampliada', 'visibilidad_ficha',
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
            $tieneLindero = array_key_exists('lindero_geojson', $datos);
            $lindero = $datos['lindero_geojson'] ?? null;
            unset($datos['lindero_geojson']);
            $geojsonNuevo = $lindero === null ? null : json_encode($lindero, JSON_THROW_ON_ERROR);
            if ($tieneLindero && $lindero !== null) {
                try {
                    $valid = DB::selectOne('SELECT ST_IsValid(g) AS valido, GeometryType(g) AS tipo, ST_XMin(g) AS min_lon, ST_XMax(g) AS max_lon, ST_YMin(g) AS min_lat, ST_YMax(g) AS max_lat FROM (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) AS g) q', [$geojsonNuevo]);
                } catch (QueryException) {
                    throw ValidationException::withMessages(['lindero_geojson' => 'El lindero debe ser un GeoJSON válido.']);
                }
                if (! $valid->valido || ! in_array($valid->tipo, ['POLYGON', 'MULTIPOLYGON'], true)
                    || $valid->min_lon < -180 || $valid->max_lon > 180 || $valid->min_lat < -90 || $valid->max_lat > 90) {
                    throw ValidationException::withMessages(['lindero_geojson' => 'El lindero debe ser un polígono válido.']);
                }
            }
            $geojsonNormalizado = $geojsonNuevo === null ? null : DB::scalar('SELECT ST_AsGeoJSON(ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)))', [$geojsonNuevo]);
            $geojsonActual = $tieneLindero ? DB::scalar('SELECT ST_AsGeoJSON(lindero) FROM establecimientos WHERE id = ?', [$e->id]) : null;
            $cambioLindero = $tieneLindero && $geojsonNormalizado !== $geojsonActual;
            $e->fill(array_intersect_key($datos, array_flip(self::CAMPOS)));
            if (array_key_exists('zonas_sanitarias', $datos)) {
                $e->zonas_sanitarias = [...CrearEstablecimiento::zonasConDefaults([], $e->departamento_codigo), ...$e->zonas_sanitarias, ...$datos['zonas_sanitarias']];
            }

            if (! $e->isDirty() && ! $cambioLindero) {
                return $e;
            }

            $e->row_version++;
            $e->save();
            if ($cambioLindero) {
                DB::update('UPDATE establecimientos SET lindero = CASE WHEN ?::text IS NULL THEN NULL ELSE ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) END WHERE id = ?', [$geojsonNuevo, $geojsonNuevo, $e->id]);
            }
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
        $s['lindero_geojson'] = DB::scalar('SELECT lindero IS NOT NULL FROM establecimientos WHERE id = ?', [$e->id]) ? '(definido)' : null;

        return $s;
    }
}
