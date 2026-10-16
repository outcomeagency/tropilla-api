<?php

namespace App\Http\Resources\V1\Sanidad;

use App\Domain\Sanidad\Models\ProductoSanitario;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `ProductoSanitario`.
 *
 * @property ProductoSanitario $resource
 */
class ProductoSanitarioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'id' => $p->id,
            'nombre_comercial' => $p->nombre_comercial,
            'principio_activo' => $p->principio_activo,
            'tipo' => $p->tipo,
            'via' => $p->via,
            'dosis_declarada' => $p->dosis_declarada,
            'retiro_carne_dias' => $p->retiro_carne_dias,
            'retiro_leche_dias' => $p->retiro_leche_dias,
            'especies' => $p->especies,
            'enfermedades' => $p->enfermedades,
            'notas' => $p->notas,
            'archivado_en' => Formato::fechaHora($p->archivado_en),
            'row_version' => $p->row_version,
        ];
    }
}
