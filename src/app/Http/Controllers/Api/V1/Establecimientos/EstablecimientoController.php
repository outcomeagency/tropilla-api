<?php

namespace App\Http\Controllers\Api\V1\Establecimientos;

use App\Domain\Establecimientos\Actions\CrearEstablecimientoDeProductor;
use App\Domain\Establecimientos\Actions\ListarMisEstablecimientos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Establecimientos\CrearEstablecimientoRequest;
use App\Http\Resources\V1\Establecimientos\EstablecimientoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** /establecimientos (TPA-10, 12, 13). */
class EstablecimientoController extends Controller
{
    /** Selector (TPA-12): mis establecimientos con rol y abilities. */
    public function index(Request $request, ListarMisEstablecimientos $listar): AnonymousResourceCollection
    {
        return EstablecimientoResource::collection($listar->handle($request->user()));
    }

    public function store(CrearEstablecimientoRequest $request, CrearEstablecimientoDeProductor $crear): JsonResponse
    {
        return EstablecimientoResource::make($crear->handle($request->user(), $request->validated()))
            ->response()->setStatusCode(201);
    }

    public function show(Establecimiento $establecimiento): EstablecimientoResource
    {
        Gate::authorize('establecimiento.ver', $establecimiento);

        return EstablecimientoResource::make($establecimiento->load('productor'));
    }
}
