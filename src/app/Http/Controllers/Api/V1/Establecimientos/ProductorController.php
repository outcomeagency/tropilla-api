<?php

namespace App\Http\Controllers\Api\V1\Establecimientos;

use App\Domain\Establecimientos\Actions\ActualizarProductor;
use App\Domain\Establecimientos\Models\Productor;
use App\Domain\Establecimientos\Policies\ProductorPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Establecimientos\ActualizarProductorRequest;
use App\Http\Resources\V1\Establecimientos\ProductorResource;
use Illuminate\Support\Facades\Gate;

/** GET|PATCH /productores/{productor} (TPA-10). */
class ProductorController extends Controller
{
    public function show(Productor $productor): ProductorResource
    {
        Gate::authorize('view', $productor);

        return ProductorResource::make($productor);
    }

    public function update(ActualizarProductorRequest $request, Productor $productor, ActualizarProductor $actualizar): ProductorResource
    {
        Gate::authorize('update', $productor);

        return ProductorResource::make($actualizar->handle(
            $productor, $request->user(), $request->validated(),
            ProductorPolicy::establecimientoEditable($request->user(), $productor),
        ));
    }
}
