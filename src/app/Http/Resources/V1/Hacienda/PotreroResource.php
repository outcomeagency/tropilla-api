<?php

namespace App\Http\Resources\V1\Hacienda;

use App\Domain\Hacienda\Models\Potrero;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `Potrero` (+ `poligono` GeoJSON, desvío aditivo documentado). `ocupacion` se precarga
 * en el atributo `ocupacion` (OcupacionPotreros) para no consultar por fila.
 *
 * @property Potrero $resource
 */
class PotreroResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $geo = $p->getAttribute('poligono_geojson');

        return [
            'id' => $p->id,
            'nombre' => $p->nombre,
            'superficie_ha' => Formato::decimal($p->superficie_ha),
            'ambiente' => $p->ambiente,
            'recurso_forrajero' => $p->recurso_forrajero,
            'agua' => $p->agua,
            'revolcadero' => $p->revolcadero,
            'notas' => $p->notas,
            'poligono' => $geo ? json_decode($geo, true) : null,
            'ocupacion' => $p->getAttribute('ocupacion') ?? ['cabezas' => 0, 'lotes' => []],
            'row_version' => $p->row_version,
            'archivado_en' => Formato::fechaHora($p->archivado_en),
        ];
    }
}
