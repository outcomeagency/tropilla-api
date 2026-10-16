<?php

namespace App\Http\Resources\V1\Auth;

use App\Models\User;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `Usuario` del contrato.
 *
 * @property User $resource
 */
class UsuarioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $u = $this->resource;

        return [
            'id' => $u->id,
            'nombre' => $u->nombre,
            'email' => $u->email,
            'telefono' => $u->telefono,
            'email_verificado' => $u->emailVerificado(),
            'idioma' => $u->idioma,
            'pais' => $u->pais,
            'foto_url' => null, // archivos firmados: TPA-44
            'es_staff' => (bool) $u->es_staff,
            'es_comprador' => false, // TPA-57
            'es_profesional' => false, // TPA-58
            'created_at' => Formato::fechaHora($u->created_at),
        ];
    }
}
