<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Cuentas\Actions\ActualizarUsuario;
use App\Domain\Cuentas\Actions\CerrarSesion;
use App\Domain\Cuentas\Actions\EnviarVerificacionEmail;
use App\Domain\Cuentas\Actions\IniciarSesion;
use App\Domain\Cuentas\Actions\RegistrarUsuario;
use App\Domain\Cuentas\Actions\RestablecerPassword;
use App\Domain\Cuentas\Actions\SolicitarRestablecimiento;
use App\Domain\Cuentas\Actions\VerificarEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\ActualizarUsuarioRequest;
use App\Http\Requests\V1\Auth\LoginRequest;
use App\Http\Requests\V1\Auth\OlvideRequest;
use App\Http\Requests\V1\Auth\RegisterRequest;
use App\Http\Requests\V1\Auth\RestablecerRequest;
use App\Http\Requests\V1\Auth\VerificarEmailRequest;
use App\Http\Resources\V1\Auth\MeResource;
use App\Http\Resources\V1\Auth\TokenRespuestaResource;
use App\Http\Resources\V1\Auth\UsuarioResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /api/v1/auth/* (TPA-9). */
class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegistrarUsuario $registrar): JsonResponse
    {
        return TokenRespuestaResource::make($registrar->handle($request->validated()))
            ->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request, IniciarSesion $iniciar): TokenRespuestaResource
    {
        return TokenRespuestaResource::make($iniciar->handle($request->validated()));
    }

    public function logout(Request $request, CerrarSesion $cerrar): Response
    {
        $cerrar->handle($request->user());

        return response()->noContent();
    }

    public function me(Request $request): MeResource
    {
        return MeResource::make($request->user());
    }

    public function actualizarMe(ActualizarUsuarioRequest $request, ActualizarUsuario $actualizar): UsuarioResource
    {
        return UsuarioResource::make($actualizar->handle($request->user(), $request->validated()));
    }

    public function reenviarVerificacion(Request $request, EnviarVerificacionEmail $enviar): Response
    {
        $enviar->handle($request->user());

        return response()->noContent();
    }

    public function verificarEmail(VerificarEmailRequest $request, VerificarEmail $verificar): Response
    {
        $verificar->handle($request->validated('token'));

        return response()->noContent();
    }

    public function olvide(OlvideRequest $request, SolicitarRestablecimiento $solicitar): Response
    {
        $solicitar->handle($request->validated());

        return response()->noContent();
    }

    public function restablecer(RestablecerRequest $request, RestablecerPassword $restablecer): Response
    {
        $restablecer->handle($request->validated('token'), $request->validated('password'));

        return response()->noContent();
    }
}
