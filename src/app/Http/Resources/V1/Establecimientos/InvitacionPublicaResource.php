<?php

namespace App\Http\Resources\V1\Establecimientos;

use App\Domain\Establecimientos\Models\Invitacion;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `InvitacionPublica` (antes de loguearse). Whitelist: sin emails, teléfonos ni ids.
 *
 * @property Invitacion $resource
 */
class InvitacionPublicaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'establecimiento_nombre' => $i->establecimiento->nombre,
            'productor_nombre' => $i->establecimiento->productor->nombre,
            'invitado_por' => $i->creador->nombre,
            'rol' => $i->rol,
            'expira_en' => Formato::fechaHora($i->expira_en),
            'vigente' => $i->vigente(),
        ];
    }
}
