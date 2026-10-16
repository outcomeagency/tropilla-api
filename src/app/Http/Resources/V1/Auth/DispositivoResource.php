<?php

namespace App\Http\Resources\V1\Auth;

use App\Domain\Cuentas\Models\Dispositivo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `DispositivoInput` (el contrato devuelve la misma forma).
 *
 * @property Dispositivo $resource
 */
class DispositivoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'plataforma' => $this->resource->plataforma,
            'nombre' => $this->resource->nombre,
            'app_version' => $this->resource->app_version,
            'fcm_token' => $this->resource->fcm_token,
        ];
    }
}
