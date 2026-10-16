<?php

namespace App\Http\Resources\V1\Hacienda;

use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Schema `Revision`. */
class RevisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r->id, 'tipo' => $r->tipo, 'entidad_tipo' => $r->entidad_tipo, 'entidad_id' => $r->entidad_id,
            'detalle' => (object) json_decode($r->detalle, true), 'estado' => $r->estado,
            'resuelta_por' => $r->resuelta_por ? ['id' => $r->resuelta_por, 'nombre' => DB::table('usuarios')->where('id', $r->resuelta_por)->value('nombre')] : null,
            'resuelta_en' => $r->resuelta_en ? Formato::fechaHora(Carbon::parse($r->resuelta_en)) : null,
            'resolucion_motivo' => $r->resolucion_motivo,
            'created_at' => Formato::fechaHora(Carbon::parse($r->created_at)),
        ];
    }
}
