<?php

namespace App\Http\Resources\V1\Establecimientos;

use App\Domain\Establecimientos\Models\Invitacion;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `Invitacion`. `url` solo en la respuesta de creación (el token no se guarda).
 *
 * @property Invitacion $resource
 */
class InvitacionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => $i->id,
            'email' => $i->email,
            'telefono' => $i->telefono,
            'rol' => $i->rol,
            'expira_en' => Formato::fechaHora($i->expira_en),
            'aceptada_en' => Formato::fechaHora($i->aceptada_en),
            'revocada_en' => Formato::fechaHora($i->revocada_en),
            'url' => $i->url(),
        ];
    }
}
