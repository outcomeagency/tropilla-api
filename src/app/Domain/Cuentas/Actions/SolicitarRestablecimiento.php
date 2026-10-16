<?php

namespace App\Domain\Cuentas\Actions;

use App\Domain\Cuentas\Notifications\RestablecerPasswordNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * «Olvidé mi contraseña». Nunca revela si la cuenta existe (el endpoint responde 204 siempre).
 * Por email: manda el link. Por teléfono: no hay proveedor de SMS en el MVP → se registra
 * en el log y no se envía nada (pendiente de proveedor; ver docs/PROGRESS.md TPA-9).
 */
final class SolicitarRestablecimiento
{
    /** @param array{email?: string|null, telefono?: string|null} $datos */
    public function handle(array $datos): void
    {
        $campo = ! empty($datos['email']) ? 'email' : 'telefono';

        /** @var User|null $usuario */
        $usuario = User::query()->where($campo, $datos[$campo])->first();
        if ($usuario === null || $usuario->bloqueado_en !== null) {
            return;
        }

        $token = Str::random(64);

        DB::table('restablecimientos_password')->insert([
            'id' => (string) Str::uuid7(),
            'usuario_id' => $usuario->id,
            'token_hash' => hash('sha256', $token),
            'expira_en' => now()->addMinutes(config('tropilla.auth.restablecer_password_minutos')),
            'created_at' => now(),
        ]);

        if ($campo === 'email') {
            $usuario->notify(new RestablecerPasswordNotification($token));
        } else {
            Log::info('Restablecimiento de contraseña por teléfono solicitado (sin proveedor SMS).', ['usuario_id' => $usuario->id]);
        }
    }
}
