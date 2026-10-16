<?php

namespace App\Http\Controllers\Api\V1\Establecimientos;

use App\Domain\Establecimientos\Actions\AceptarInvitacion;
use App\Domain\Establecimientos\Actions\InvitarUsuario;
use App\Domain\Establecimientos\Actions\RevocarInvitacion;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Invitacion;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Establecimientos\InvitacionRequest;
use App\Http\Resources\V1\Establecimientos\InvitacionPublicaResource;
use App\Http\Resources\V1\Establecimientos\InvitacionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Invitaciones (TPA-11). */
class InvitacionController extends Controller
{
    public function index(Establecimiento $establecimiento): AnonymousResourceCollection
    {
        Gate::authorize('usuarios.gestionar', $establecimiento);

        return InvitacionResource::collection($establecimiento->invitaciones()->latest()->get());
    }

    public function store(InvitacionRequest $request, Establecimiento $establecimiento, InvitarUsuario $invitar): JsonResponse
    {
        Gate::authorize('usuarios.gestionar', $establecimiento);

        return InvitacionResource::make($invitar->handle($establecimiento, $request->user(), $request->validated()))
            ->response()->setStatusCode(201);
    }

    public function revocar(Request $request, Establecimiento $establecimiento, Invitacion $invitacion, RevocarInvitacion $revocar): Response
    {
        Gate::authorize('usuarios.gestionar', $establecimiento);
        $revocar->handle($invitacion, $request->user());

        return response()->noContent();
    }

    /** GET /public/invitaciones/{token}: lo que ve el invitado antes de loguearse. */
    public function publica(string $token): InvitacionPublicaResource
    {
        $invitacion = Invitacion::porToken($token);
        if ($invitacion === null || $invitacion->establecimiento->archivado_en !== null) {
            throw ApiException::noEncontrado(__('La invitación no existe.'));
        }

        return InvitacionPublicaResource::make($invitacion->load('establecimiento.productor', 'creador'));
    }

    /** POST /invitaciones/{token}/aceptar → MembresiaResumen. */
    public function aceptar(Request $request, string $token, AceptarInvitacion $aceptar): JsonResponse
    {
        $m = $aceptar->handle($token, $request->user());

        return response()->json(['data' => [
            'id' => $m->id,
            'establecimiento_id' => $m->establecimiento_id,
            'establecimiento_nombre' => $m->establecimiento->nombre,
            'productor_nombre' => $m->establecimiento->productor->nombre,
            'rol' => $m->rol->value,
            'abilities' => $m->rol->abilities(),
        ]]);
    }
}
