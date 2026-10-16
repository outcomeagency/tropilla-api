<?php

namespace App\Http\Resources\V1\Sistema;

use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** Schema `Auditoria` (fila de la bitácora, con el usuario resumido). */
class AuditoriaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'id' => (int) $a->id,
            'entidad' => $a->entidad,
            'entidad_id' => (string) $a->entidad_id,
            'accion' => $a->accion,
            'antes' => $a->antes === null ? null : json_decode($a->antes, true),
            'despues' => $a->despues === null ? null : json_decode($a->despues, true),
            'motivo' => $a->motivo,
            'usuario' => $a->usuario_id === null ? null : ['id' => $a->usuario_id, 'nombre' => $a->usuario_nombre],
            'created_at' => Formato::fechaHora(Carbon::parse($a->created_at)),
        ];
    }
}
