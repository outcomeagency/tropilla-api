<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Cuentas\Actions\ActualizarDispositivo;
use App\Domain\Cuentas\Actions\RevocarDispositivo;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\DispositivoRequest;
use App\Http\Resources\V1\Auth\DispositivoResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /api/v1/auth/dispositivos/{dispositivo} (TPA-9). */
class DispositivoController extends Controller
{
    public function update(DispositivoRequest $request, string $dispositivo, ActualizarDispositivo $actualizar): DispositivoResource
    {
        return DispositivoResource::make($actualizar->handle($request->user(), $dispositivo, $request->validated()));
    }

    public function revocar(Request $request, string $dispositivo, RevocarDispositivo $revocar): Response
    {
        $revocar->handle($request->user(), $dispositivo);

        return response()->noContent();
    }
}
