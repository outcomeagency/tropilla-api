<?php

namespace App\Http\Resources\V1\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `TokenRespuesta`: {token, usuario, membresias}.
 *
 * @property array{usuario: User, token: string} $resource
 */
class TokenRespuestaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->resource['token'],
            'usuario' => UsuarioResource::make($this->resource['usuario'])->toArray($request),
            'membresias' => MembresiaResumenResource::deUsuario($this->resource['usuario'], $request),
        ];
    }
}
