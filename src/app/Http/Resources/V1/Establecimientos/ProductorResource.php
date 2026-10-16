<?php

namespace App\Http\Resources\V1\Establecimientos;

use App\Domain\Establecimientos\Models\Productor;
use App\Domain\Establecimientos\Policies\ProductorPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `Productor`. CUIT solo para dueño/admin de algún establecimiento del productor; si no, null.
 *
 * @property Productor $resource
 */
class ProductorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'id' => $p->id,
            'tipo' => $p->tipo,
            'nombre' => $p->nombre,
            'cuit' => ProductorPolicy::puedeVerCuit($request->user(), $p) ? $p->cuit() : null,
            'contacto' => (object) ($p->contacto ?? []),
            'asociaciones' => $p->asociaciones ?? [],
            'logo_url' => null, // archivos firmados: TPA-44
            'row_version' => $p->row_version,
        ];
    }
}
