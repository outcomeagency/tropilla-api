<?php

namespace App\Domain\Organizaciones\Policies;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OrganizacionPolicy
{
    public function administrarIntegrantes(User $user, string $organizacionId): bool
    {
        return $user->es_staff || DB::table('organizacion_miembros')->where('organizacion_id', $organizacionId)
            ->where('usuario_id', $user->id)->where('rol', 'admin')->where('estado', 'activa')->exists();
    }
}
