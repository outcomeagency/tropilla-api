<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sistema\Actions\VerificarSalud;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\HealthResource;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/health — 200 si todas las dependencias responden, 503 si no.
 * Ejemplo mínimo de la convención: controller fino → Action → Resource
 * (sin FormRequest porque no recibe input).
 */
class HealthController extends Controller
{
    public function __invoke(VerificarSalud $verificarSalud): JsonResponse
    {
        $estado = $verificarSalud->handle();

        return HealthResource::make($estado)
            ->response()
            ->setStatusCode($estado->ok() ? 200 : 503);
    }
}
