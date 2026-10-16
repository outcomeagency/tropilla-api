<?php

namespace App\Http\Controllers\Api\V1\Establecimientos;

use App\Domain\Establecimientos\Actions\GestionarMembresias;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Establecimientos\RolRequest;
use App\Http\Requests\V1\Establecimientos\TitularidadRequest;
use App\Http\Requests\V1\MotivoRequest;
use App\Http\Resources\V1\Establecimientos\MembresiaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Usuarios y roles del establecimiento (TPA-11). */
class MembresiaController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): AnonymousResourceCollection
    {
        Gate::authorize('usuarios.ver', $establecimiento);
        $estado = $request->query('estado', 'activa');

        $membresias = $establecimiento->membresias()
            ->with('usuario')
            ->when(in_array($estado, ['activa', 'revocada'], true), fn ($q) => $q->where('estado', $estado))
            ->orderByRaw("CASE rol WHEN 'dueno' THEN 0 WHEN 'admin' THEN 1 WHEN 'encargado' THEN 2 WHEN 'asesor' THEN 3 ELSE 4 END")
            ->orderBy('aceptada_en')
            ->get();

        return MembresiaResource::collection($membresias);
    }

    public function rol(RolRequest $request, Establecimiento $establecimiento, Membresia $membresia, GestionarMembresias $gestionar): JsonResponse
    {
        Gate::authorize('usuarios.gestionar', $establecimiento);

        return MembresiaResource::make($gestionar->cambiarRol($establecimiento, $membresia, $request->user(), $request->validated('rol'), $request->validated('motivo')))
            ->response()->setStatusCode(200);
    }

    public function revocar(MotivoRequest $request, Establecimiento $establecimiento, Membresia $membresia, GestionarMembresias $gestionar): Response
    {
        Gate::authorize('usuarios.gestionar', $establecimiento);
        $gestionar->revocarAcceso($establecimiento, $membresia, $request->user(), $request->validated('motivo'));

        return response()->noContent();
    }

    public function titularidad(TitularidadRequest $request, Establecimiento $establecimiento, GestionarMembresias $gestionar): Response
    {
        Gate::authorize('titularidad.transferir', $establecimiento);
        $gestionar->transferirTitularidad($establecimiento, $request->user(), $request->validated('usuario_id'), $request->validated('motivo'));

        return response()->noContent();
    }
}
