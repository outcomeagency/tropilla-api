<?php

namespace App\Domain\Cuentas\Actions;

use App\Domain\Cuentas\Models\Dispositivo;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Restablece la contraseña con el token del mail y **revoca todos los tokens** (api-v1.md §2).
 */
final class RestablecerPassword
{
    public function handle(string $token, string $password): void
    {
        DB::transaction(function () use ($token, $password) {
            $fila = DB::table('restablecimientos_password')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($fila === null || $fila->usado_en !== null || now()->greaterThan($fila->expira_en)) {
                throw ApiException::validacion(__('El enlace para restablecer la contraseña no es válido o venció.'), ['token' => [__('Token inválido o vencido.')]]);
            }

            /** @var User $usuario */
            $usuario = User::query()->findOrFail($fila->usuario_id);
            $usuario->forceFill(['password' => $password])->save();
            $usuario->tokens()->delete();
            Dispositivo::query()->where('usuario_id', $usuario->id)->update(['token_id' => null]);

            DB::table('restablecimientos_password')->where('id', $fila->id)->update(['usado_en' => now()]);
        });
    }
}
