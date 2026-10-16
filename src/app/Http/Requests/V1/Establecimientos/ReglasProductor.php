<?php

namespace App\Http\Requests\V1\Establecimientos;

use App\Rules\Cuit;
use App\Rules\Reglas;

final class ReglasProductor
{
    /** @return array<string, mixed> */
    public static function reglas(string $prefijo = '', bool $parcial = false): array
    {
        $req = $parcial ? 'sometimes' : 'required';

        return [
            $prefijo.'tipo' => [$req, 'in:persona_humana,sociedad'],
            $prefijo.'nombre' => [$req, 'string', 'max:200'],
            $prefijo.'cuit' => ['sometimes', 'nullable', 'string', new Cuit],
            $prefijo.'contacto' => ['sometimes', 'array:telefono,email,whatsapp'],
            $prefijo.'contacto.telefono' => ['sometimes', 'nullable', 'string', 'max:40'],
            $prefijo.'contacto.email' => ['sometimes', 'nullable', 'email', 'max:254'],
            $prefijo.'contacto.whatsapp' => ['sometimes', 'nullable', 'string', 'max:40'],
            $prefijo.'asociaciones' => ['sometimes', 'array', 'max:20'],
            $prefijo.'asociaciones.*' => ['array:entidad,numero_socio'],
            $prefijo.'asociaciones.*.entidad' => ['required', 'string', 'max:80'],
            $prefijo.'asociaciones.*.numero_socio' => ['nullable', 'string', 'max:40'],
            $prefijo.'logo_sha256' => ['sometimes', 'nullable', Reglas::HASH],
        ];
    }
}
