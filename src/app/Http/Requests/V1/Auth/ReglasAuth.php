<?php

namespace App\Http\Requests\V1\Auth;

/** Reglas compartidas de cuenta (contrato: RegisterInput / LoginInput / DispositivoInput). */
final class ReglasAuth
{
    public const TELEFONO = 'regex:/^\+[1-9][0-9]{7,14}$/'; // E.164

    /** @return array<string, mixed> */
    public static function dispositivo(string $prefijo = 'dispositivo.', bool $requerido = false): array
    {
        return [
            rtrim($prefijo, '.') => $requerido ? ['required', 'array'] : ['required_if:cliente,app', 'array'],
            $prefijo.'id' => ['required_with:'.rtrim($prefijo, '.'), 'uuid'],
            $prefijo.'plataforma' => ['required_with:'.rtrim($prefijo, '.'), 'in:android,ios,web'],
            $prefijo.'nombre' => ['nullable', 'string', 'max:120'],
            $prefijo.'app_version' => ['nullable', 'string', 'max:40'],
            $prefijo.'fcm_token' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
