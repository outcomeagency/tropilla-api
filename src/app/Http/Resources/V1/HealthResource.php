<?php

namespace App\Http\Resources\V1;

use App\Domain\Sistema\Data\EstadoDelSistema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property EstadoDelSistema $resource
 */
class HealthResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource->ok() ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'version' => 'v1',
            'time' => now()->toIso8601ZuluString(),
            'checks' => $this->resource->chequeos,
        ];
    }
}
