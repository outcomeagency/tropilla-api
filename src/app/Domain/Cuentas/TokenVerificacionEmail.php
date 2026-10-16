<?php

namespace App\Domain\Cuentas;

use App\Models\User;

/**
 * Token de verificación de email sin estado: base64url(json{u, h, exp}) + "." + HMAC.
 * `h` = SHA-256 del email: si el usuario cambia el email, los tokens viejos dejan de servir.
 */
final class TokenVerificacionEmail
{
    public static function generar(User $usuario): string
    {
        $datos = self::b64(json_encode([
            'u' => $usuario->id,
            'h' => hash('sha256', mb_strtolower((string) $usuario->email)),
            'exp' => now()->addHours(config('tropilla.auth.verificacion_email_horas'))->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return $datos.'.'.self::firma($datos);
    }

    public static function validar(string $token): ?User
    {
        $partes = explode('.', $token);
        if (count($partes) !== 2 || ! hash_equals(self::firma($partes[0]), $partes[1])) {
            return null;
        }

        $datos = json_decode((string) base64_decode(strtr($partes[0], '-_', '+/'), true), true);
        if (! is_array($datos) || ($datos['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        /** @var User|null $usuario */
        $usuario = User::query()->find($datos['u'] ?? null);
        if ($usuario === null || $usuario->email === null
            || ! hash_equals(hash('sha256', mb_strtolower($usuario->email)), (string) ($datos['h'] ?? ''))) {
            return null;
        }

        return $usuario;
    }

    private static function firma(string $datos): string
    {
        return self::b64(hash_hmac('sha256', 'verificacion-email|'.$datos, (string) config('app.key'), true));
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
