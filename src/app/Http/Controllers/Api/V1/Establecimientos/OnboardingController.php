<?php

namespace App\Http\Controllers\Api\V1\Establecimientos;

use App\Domain\Establecimientos\Actions\RealizarOnboarding;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Establecimientos\OnboardingRequest;
use App\Http\Resources\V1\Establecimientos\EstablecimientoResource;
use Illuminate\Http\JsonResponse;

/** POST /onboarding (TPA-10). */
class OnboardingController extends Controller
{
    public function __invoke(OnboardingRequest $request, RealizarOnboarding $onboarding): JsonResponse
    {
        return EstablecimientoResource::make($onboarding->handle($request->user(), $request->validated()))
            ->response()->setStatusCode(201);
    }
}
