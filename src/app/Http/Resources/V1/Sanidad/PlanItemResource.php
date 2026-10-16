<?php

namespace App\Http\Resources\V1\Sanidad;

use App\Domain\Sanidad\GeneradorTareas;
use App\Domain\Sanidad\Models\PlanItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `PlanItem` (+ aditivos `nota`, `aplica` = condición de zona/especie cumplida, `requiere_configuracion` = faltan fechas,
 * `archivado_en`). `aplica` se precarga en el atributo `aplica`.
 *
 * @property PlanItem $resource
 */
class PlanItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => $i->id,
            'plantilla_codigo' => $i->plantilla_codigo,
            'nombre' => $i->nombre,
            'especie' => $i->especie_codigo,
            'tipo_evento' => $i->tipo_evento,
            'enfermedad' => $i->enfermedad,
            'producto_id' => $i->producto_id,
            'alcance' => (object) ($i->alcance ?? []),
            'disparador' => (object) $i->disparador,
            'repeticion' => $i->repeticion === null ? null : (object) $i->repeticion,
            'condicion' => $i->condicion === null ? null : (object) $i->condicion,
            'nota' => $i->nota,
            'activo' => $i->activo,
            'editado' => $i->editado,
            'aplica' => (bool) ($i->getAttribute('aplica') ?? true),
            'requiere_configuracion' => GeneradorTareas::requiereConfiguracion($i),
            'archivado_en' => $i->archivado_en?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'row_version' => $i->row_version,
        ];
    }
}
