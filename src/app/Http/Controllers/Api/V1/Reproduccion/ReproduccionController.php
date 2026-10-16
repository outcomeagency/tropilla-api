<?php

namespace App\Http\Controllers\Api\V1\Reproduccion;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Reproduccion\TableroReproductivo;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Reproduccion\ReproduccionRequest;
use App\Http\Resources\V1\Reproduccion\PartoCalendarioResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Reproducción (TPA-30): tablero de vientres y calendario de partos probables. */
class ReproduccionController extends Controller
{
    public function tablero(ReproduccionRequest $request, Establecimiento $establecimiento, TableroReproductivo $tablero): JsonResponse
    {
        return response()->json(['data' => $tablero->tablero($establecimiento, $request->filtros()['especie'])]);
    }

    public function partos(ReproduccionRequest $request, Establecimiento $establecimiento, TableroReproductivo $tablero): AnonymousResourceCollection
    {
        return PartoCalendarioResource::collection($tablero->partos($establecimiento, $request->filtros()));
    }
}
