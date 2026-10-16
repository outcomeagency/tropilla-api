<?php

namespace App\Http\Controllers\Api\V1\Hacienda;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Actions\GuardarPotrero;
use App\Domain\Hacienda\Models\Potrero;
use App\Domain\Hacienda\Services\OcupacionPotreros;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hacienda\PotreroRequest;
use App\Http\Requests\V1\MotivoRequest;
use App\Http\Resources\V1\Hacienda\PotreroResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/** Potreros (TPA-14). */
class PotreroController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): AnonymousResourceCollection
    {
        Gate::authorize('establecimiento.ver', $establecimiento);

        $potreros = Potrero::query()
            ->where('establecimiento_id', $establecimiento->id)
            ->when(! $request->boolean('archivados'), fn ($q) => $q->whereNull('archivado_en'))
            ->select('potreros.*')->selectRaw('ST_AsGeoJSON(poligono) as poligono_geojson')
            ->orderByRaw('lower(nombre)')
            ->get();

        return PotreroResource::collection($this->conOcupacion($establecimiento, $potreros));
    }

    public function store(PotreroRequest $request, Establecimiento $establecimiento, GuardarPotrero $guardar): JsonResponse
    {
        Gate::authorize('maestros.editar', $establecimiento);
        ['potrero' => $p, 'creado' => $creado] = $guardar->crear($establecimiento, $request->user(), $request->validated());

        return PotreroResource::make($this->cargar($establecimiento, $p))->response()->setStatusCode($creado ? 201 : 200);
    }

    public function show(Establecimiento $establecimiento, Potrero $potrero): PotreroResource
    {
        Gate::authorize('establecimiento.ver', $establecimiento);

        return PotreroResource::make($this->cargar($establecimiento, $potrero));
    }

    public function update(PotreroRequest $request, Establecimiento $establecimiento, Potrero $potrero, GuardarPotrero $guardar): JsonResponse
    {
        Gate::authorize('maestros.editar', $establecimiento);
        $p = $guardar->editar($potrero, $request->user(), $request->validated());

        return PotreroResource::make($this->cargar($establecimiento, $p))->response()->setStatusCode(200);
    }

    public function archivar(MotivoRequest $request, Establecimiento $establecimiento, Potrero $potrero, GuardarPotrero $guardar): JsonResponse
    {
        Gate::authorize('maestros.editar', $establecimiento);
        $p = $guardar->archivar($potrero, $request->user(), $request->validated('motivo'));

        return PotreroResource::make($this->cargar($establecimiento, $p))->response()->setStatusCode(200);
    }

    private function cargar(Establecimiento $establecimiento, Potrero $potrero): Potrero
    {
        $p = Potrero::query()->select('potreros.*')->selectRaw('ST_AsGeoJSON(poligono) as poligono_geojson')->findOrFail($potrero->id);

        return $this->conOcupacion($establecimiento, collect([$p]))->first();
    }

    /**
     * @param  Collection<int, Potrero>  $potreros
     * @return Collection<int, Potrero>
     */
    private function conOcupacion(Establecimiento $establecimiento, Collection $potreros): Collection
    {
        $ocupacion = OcupacionPotreros::de($establecimiento->id, $potreros->pluck('id')->all());

        return $potreros->each(fn (Potrero $p) => $p->setAttribute('ocupacion', $ocupacion[$p->id]));
    }
}
