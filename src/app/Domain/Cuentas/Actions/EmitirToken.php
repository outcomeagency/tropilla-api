<?php

namespace App\Domain\Cuentas\Actions;

use App\Domain\Cuentas\Models\Dispositivo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Emite un token Sanctum (api-v1.md §2):
 * - web: abilities ['web'], vence a los 30 días (deslizante, ver AppServiceProvider).
 * - app: abilities ['app'], un token por dispositivo (UUIDv7 del teléfono), sin vencimiento.
 *   Si el dispositivo ya tenía token, se revoca: un dispositivo = un token vigente.
 *   Si el dispositivo estaba asociado a otro usuario (teléfono compartido), pasa al
 *   usuario que inicia sesión y el token anterior se revoca.
 */
final class EmitirToken
{
    /**
     * @param  array{id: string, plataforma: string, nombre?: string|null, app_version?: string|null, fcm_token?: string|null}|null  $dispositivo
     */
    public function handle(User $usuario, string $cliente, ?array $dispositivo = null): string
    {
        return DB::transaction(function () use ($usuario, $cliente, $dispositivo) {
            if ($cliente === 'web' || $dispositivo === null) {
                $token = $usuario->createToken('web', ['web'], now()->addDays(config('tropilla.auth.web_token_dias')));

                return $token->plainTextToken;
            }

            /** @var Dispositivo|null $existente */
            $existente = Dispositivo::query()->lockForUpdate()->find($dispositivo['id']);
            if ($existente?->token_id) {
                $existente->usuario->tokens()->whereKey($existente->token_id)->delete();
                $usuario->tokens()->whereKey($existente->token_id)->delete();
            }

            $token = $usuario->createToken('app:'.$dispositivo['id'], ['app']);

            Dispositivo::query()->updateOrCreate(['id' => $dispositivo['id']], [
                'usuario_id' => $usuario->id,
                'plataforma' => $dispositivo['plataforma'],
                'nombre' => $dispositivo['nombre'] ?? $existente?->nombre,
                'app_version' => $dispositivo['app_version'] ?? $existente?->app_version,
                'fcm_token' => array_key_exists('fcm_token', $dispositivo) ? $dispositivo['fcm_token'] : $existente?->fcm_token,
                'token_id' => $token->accessToken->getKey(),
                'revocado_en' => null,
            ]);

            return $token->plainTextToken;
        });
    }
}
