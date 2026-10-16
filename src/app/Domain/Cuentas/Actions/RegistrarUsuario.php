<?php

namespace App\Domain\Cuentas\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alta de cuenta con email o teléfono (TPA-9). Si hay email, envía la verificación.
 */
final class RegistrarUsuario
{
    public function __construct(
        private readonly EmitirToken $emitirToken,
        private readonly EnviarVerificacionEmail $enviarVerificacion,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  validado por RegisterRequest
     * @return array{usuario: User, token: string}
     */
    public function handle(array $datos): array
    {
        $resultado = DB::transaction(function () use ($datos) {
            $usuario = User::query()->create([
                'nombre' => $datos['nombre'],
                'email' => $datos['email'] ?? null,
                'telefono' => $datos['telefono'] ?? null,
                'password' => $datos['password'],
                'idioma' => $datos['idioma'] ?? 'es',
                'ultimo_acceso_en' => now(),
            ]);

            $token = $this->emitirToken->handle($usuario, $datos['cliente'], $datos['dispositivo'] ?? null);

            return ['usuario' => $usuario, 'token' => $token];
        });

        if ($resultado['usuario']->email !== null) {
            $this->enviarVerificacion->handle($resultado['usuario']);
        }

        return $resultado;
    }
}
