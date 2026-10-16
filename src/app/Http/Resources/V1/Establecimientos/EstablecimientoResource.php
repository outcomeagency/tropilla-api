<?php

namespace App\Http\Resources\V1\Establecimientos;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Policies\EstablecimientoPolicy;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `Establecimiento`. `renspa` y `ubicacion` son null sin `establecimiento.ver_privados`
 * (la clave está siempre: forma estable, api-v1 §5). `mi_rol` y `abilities` = membresía del usuario.
 *
 * @property Establecimiento $resource
 */
class EstablecimientoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $rol = EstablecimientoPolicy::rol($request->user(), $e);
        $privados = $rol?->puede('establecimiento.ver_privados') ?? false;

        return [
            'id' => $e->id,
            'productor_id' => $e->productor_id,
            'nombre' => $e->nombre,
            'provincia_codigo' => $e->provincia_codigo,
            'departamento_codigo' => $e->departamento_codigo,
            'localidad' => $e->localidad,
            'ubicacion' => $privados ? $e->ubicacion : null,
            'renspa' => $privados ? $e->renspa : null,
            'superficie_ha' => Formato::decimal($e->superficie_ha),
            'actividades' => $e->actividades,
            'especies' => $e->especies,
            'zonas_sanitarias' => [
                'aftosa_zona' => $e->zonas_sanitarias['aftosa_zona'] ?? null,
                'garrapata' => $e->zonas_sanitarias['garrapata'] ?? null,
                'rabia_endemica' => (bool) ($e->zonas_sanitarias['rabia_endemica'] ?? false),
                'fasciola_endemica' => (bool) ($e->zonas_sanitarias['fasciola_endemica'] ?? false),
            ],
            'interdiccion_vigente' => $e->getAttribute('interdiccion_vigente'), // derivado de eventos (TPA-22)
            'instalaciones' => $e->instalaciones,
            'historia' => $e->historia,
            'mi_rol' => $rol?->value,
            'abilities' => $rol?->abilities() ?? [],
            'row_version' => $e->row_version,
            'created_at' => Formato::fechaHora($e->created_at),
        ];
    }
}
