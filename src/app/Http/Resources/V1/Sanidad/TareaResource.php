<?php

namespace App\Http\Resources\V1\Sanidad;

use App\Domain\Sanidad\Models\Tarea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Schema `Tarea`. `estado = vencida` es derivado (pendiente con `vence_en` < hoy en la zona del establecimiento; la zona
 * se precarga en `zona_horaria`). `evento_id` = raíz del evento que la cerró. Aditivo: `reprogramada`.
 *
 * @property Tarea $resource
 */
class TareaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $t = $this->resource;
        $hoy = Carbon::now($t->getAttribute('zona_horaria') ?? 'America/Argentina/Buenos_Aires')->toDateString();

        return [
            'id' => $t->id,
            'plan_item_id' => $t->plan_item_id,
            'titulo' => $t->titulo,
            'tipo_evento' => $t->tipo_evento,
            'enfermedad' => $t->enfermedad,
            'producto_id' => $t->producto_id,
            'alcance' => (object) ($t->alcance ?? []),
            'fecha_desde' => $t->fecha_desde?->toDateString(),
            'fecha_hasta' => $t->fecha_hasta?->toDateString(),
            'vence_en' => $t->vence_en->toDateString(),
            'estado' => $t->estadoVisible($hoy),
            'evento_id' => $t->evento_raiz_id,
            'omitida_motivo' => $t->omitida_motivo,
            'cabezas_estimadas' => $t->cabezas_estimadas,
            'reprogramada' => $t->reprogramada,
            'row_version' => $t->row_version,
        ];
    }
}
