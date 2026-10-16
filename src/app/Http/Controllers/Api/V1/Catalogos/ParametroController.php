<?php

namespace App\Http\Controllers\Api\V1\Catalogos;

use App\Domain\Catalogos\Actions\AgregarCatalogoPropio;
use App\Domain\Catalogos\Actions\SobrescribirParametro;
use App\Domain\Catalogos\Parametros;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Catalogos\CatalogoPropioRequest;
use App\Http\Requests\V1\Catalogos\ParametroRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Parámetros resueltos por establecimiento y catálogo propio (TPA-15). */
class ParametroController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('establecimiento.ver', $establecimiento);
        $especie = is_string($request->query('especie')) ? $request->query('especie') : null;

        return response()->json(['data' => array_map(
            fn ($p) => array_diff_key($p, ['fuente' => 1]),
            Parametros::resueltos($establecimiento->id, $especie, $request->query('raza')),
        )]);
    }

    public function update(ParametroRequest $request, Establecimiento $establecimiento, string $clave, SobrescribirParametro $sobrescribir): JsonResponse
    {
        Gate::authorize('establecimiento.editar', $establecimiento);
        $p = $sobrescribir->handle($establecimiento, $request->user(), $clave, $request->validated());

        return response()->json(['data' => array_diff_key($p, ['fuente' => 1])]);
    }

    public function catalogoPropio(CatalogoPropioRequest $request, Establecimiento $establecimiento, AgregarCatalogoPropio $agregar): JsonResponse
    {
        Gate::authorize('maestros.editar', $establecimiento);

        return response()->json(['data' => ['codigo' => $agregar->handle($establecimiento, $request->user(), $request->validated())]], 201);
    }
}
