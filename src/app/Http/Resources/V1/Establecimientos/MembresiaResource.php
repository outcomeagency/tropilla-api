<?php

namespace App\Http\Resources\V1\Establecimientos;

use App\Domain\Establecimientos\Models\Membresia;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `Membresia`.
 *
 * @property Membresia $resource
 */
class MembresiaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'id' => $m->id,
            'rol' => $m->rol->value,
            'estado' => $m->estado,
            'usuario' => [
                'id' => $m->usuario->id,
                'nombre' => $m->usuario->nombre,
                'email' => $m->usuario->email,
                'telefono' => $m->usuario->telefono,
            ],
            'aceptada_en' => Formato::fechaHora($m->aceptada_en),
            'revocada_en' => Formato::fechaHora($m->revocada_en),
        ];
    }
}
