<?php

namespace App\Http\Resources\V1\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Schema `MembresiaResumen`. Las membresías llegan con TPA-10.
 */
final class MembresiaResumenResource
{
    /** @return list<array<string, mixed>> */
    public static function deUsuario(User $usuario, Request $request): array
    {
        return [];
    }
}
