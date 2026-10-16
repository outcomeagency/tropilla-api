<?php

namespace App\Http\Controllers\Api\V1\Hacienda;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Actions\AltaRapida;
use App\Domain\Hacienda\ConsultasAnimales;
use App\Domain\Hacienda\Proyecciones\Identificaciones;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hacienda\AltaRapidaRequest;
use App\Http\Resources\V1\Hacienda\AnimalResource;
use App\Http\Resources\V1\Hacienda\AnimalResumenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Animales (TPA-16 alta rápida, TPA-17 ficha, TPA-18 listado, TPA-19 identificador). */
class AnimalController extends Controller
{
    public function store(AltaRapidaRequest $request, Establecimiento $establecimiento, AltaRapida $alta): JsonResponse
    {
        Gate::authorize('hacienda.crear', $establecimiento);
        $id = $alta->handle($establecimiento, $request->user(), EventoController::cuerpo($request));

        return AnimalResource::make(ConsultasAnimales::uno($establecimiento->id, $id))->response()->setStatusCode(201);
    }

    public function show(Establecimiento $establecimiento, string $animal): AnimalResource
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $fila = ConsultasAnimales::uno($establecimiento->id, $animal) ?? throw ApiException::noEncontrado();

        return AnimalResource::make($fila);
    }

    /** Identificador exacto (chequeo de duplicado antes del alta): vigentes del establecimiento. */
    public function identificador(Request $request, Establecimiento $establecimiento): AnonymousResourceCollection
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $valor = Identificaciones::normalizar((string) $request->query('valor', ''));

        $ids = $valor === '' ? [] : DB::table('animal_identificaciones')->where('establecimiento_id', $establecimiento->id)
            ->where('valor_normalizado', $valor)->where('vigente', true)
            ->when($request->query('tipo'), fn ($q, $t) => $q->where('tipo', $t))
            ->distinct()->pluck('animal_id')->all();

        return AnimalResumenResource::collection(ConsultasAnimales::base($establecimiento->id)->whereIn('a.animal_id', $ids)->get());
    }
}
