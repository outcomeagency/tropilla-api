<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Establecimientos\Models\Invitacion;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RevocarInvitacion
{
    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    public function handle(Invitacion $invitacion, User $autor): void
    {
        if ($invitacion->aceptada_en !== null) {
            throw ApiException::reglaBloqueante(__('La invitación ya fue aceptada: revocá la membresía.'));
        }
        if ($invitacion->revocada_en !== null) {
            return;
        }

        DB::transaction(function () use ($invitacion, $autor) {
            $invitacion->forceFill(['revocada_en' => now()])->save();
            $this->auditoria->handle($invitacion->establecimiento_id, $autor->id, 'invitacion', $invitacion->id, 'revocar_membresia', null, ['invitacion' => 'revocada']);
        });
    }
}
