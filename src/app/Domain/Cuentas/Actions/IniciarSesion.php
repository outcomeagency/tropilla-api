<?php

namespace App\Domain\Cuentas\Actions;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Login por email o teléfono + contraseña (TPA-9). Credenciales inválidas → 422
 * con el mismo mensaje para «no existe» y «contraseña incorrecta» (no filtra cuentas).
 */
final class IniciarSesion
{
    public function __construct(private readonly EmitirToken $emitirToken) {}

    /**
     * @param  array<string, mixed>  $datos  validado por LoginRequest
     * @return array{usuario: User, token: string}
     */
    public function handle(array $datos): array
    {
        $campo = ! empty($datos['email']) ? 'email' : 'telefono';

        /** @var User|null $usuario */
        $usuario = User::query()->where($campo, $datos[$campo])->first();

        if ($usuario === null || ! Hash::check($datos['password'], $usuario->password)) {
            throw ApiException::validacion(__('El :campo o la contraseña no coinciden.', ['campo' => $campo === 'email' ? 'email' : 'teléfono']), [
                $campo => [__('Credenciales incorrectas.')],
            ]);
        }

        if ($usuario->bloqueado_en !== null) {
            throw ApiException::sinPermiso(__('La cuenta está bloqueada.'));
        }

        $usuario->forceFill(['ultimo_acceso_en' => now()])->save();

        return [
            'usuario' => $usuario,
            'token' => $this->emitirToken->handle($usuario, $datos['cliente'], $datos['dispositivo'] ?? null),
        ];
    }
}
