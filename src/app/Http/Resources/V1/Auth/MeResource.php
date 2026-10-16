<?php

namespace App\Http\Resources\V1\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `Me`: usuario + membresías con abilities calculadas (la UI no reimplementa la matriz).
 *
 * @property User $resource
 */
class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'usuario' => UsuarioResource::make($this->resource)->toArray($request),
            'membresias' => MembresiaResumenResource::deUsuario($this->resource, $request),
            'comprador' => null, // TPA-57
            'profesional' => null, // TPA-58
            'organizaciones' => [], // TPA-51
        ];
    }
}
