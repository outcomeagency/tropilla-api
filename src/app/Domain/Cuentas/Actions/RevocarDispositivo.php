<?php

namespace App\Domain\Cuentas\Actions;

use App\Domain\Cuentas\Models\Dispositivo;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Revoca un dispositivo propio y su token (p. ej. teléfono perdido). */
final class RevocarDispositivo
{
    public function handle(User $usuario, string $id): void
    {
        DB::transaction(function () use ($usuario, $id) {
            /** @var Dispositivo|null $dispositivo */
            $dispositivo = Dispositivo::query()->where('usuario_id', $usuario->id)->lockForUpdate()->find($id);
            if ($dispositivo === null) {
                throw ApiException::noEncontrado();
            }

            if ($dispositivo->token_id !== null) {
                $usuario->tokens()->whereKey($dispositivo->token_id)->delete();
            }

            $dispositivo->forceFill(['revocado_en' => now(), 'token_id' => null, 'fcm_token' => null])->save();
        });
    }
}
