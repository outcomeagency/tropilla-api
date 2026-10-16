<?php

namespace App\Domain\Cuentas\Actions;

use App\Domain\Cuentas\Models\Dispositivo;
use App\Exceptions\ApiException;
use App\Models\User;

/** PUT /auth/dispositivos/{id}: FCM token, versión de app, nombre. Solo dispositivos propios. */
final class ActualizarDispositivo
{
    /** @param array<string, mixed> $datos */
    public function handle(User $usuario, string $id, array $datos): Dispositivo
    {
        /** @var Dispositivo|null $dispositivo */
        $dispositivo = Dispositivo::query()->where('usuario_id', $usuario->id)->find($id);
        if ($dispositivo === null) {
            throw ApiException::noEncontrado();
        }

        $dispositivo->fill(array_intersect_key($datos, array_flip(['plataforma', 'nombre', 'app_version', 'fcm_token'])))->save();

        return $dispositivo;
    }
}
