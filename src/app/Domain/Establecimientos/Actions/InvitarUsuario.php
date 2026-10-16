<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Invitacion;
use App\Domain\Establecimientos\Notifications\InvitacionNotification;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invita por email o teléfono con rol (TPA-11). Nunca `dueno` (eso es transferencia de titularidad).
 * Por email se manda el link; por teléfono no hay SMS en el MVP: la respuesta trae `url` para que
 * el dueño la comparta (WhatsApp). El token solo vive en esa respuesta y en el mail.
 */
final class InvitarUsuario
{
    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /** @param array{rol: string, email?: string|null, telefono?: string|null} $datos */
    public function handle(Establecimiento $establecimiento, User $autor, array $datos): Invitacion
    {
        $invitacion = DB::transaction(function () use ($establecimiento, $autor, $datos) {
            $token = Str::random(48);
            $invitacion = Invitacion::query()->create([
                'establecimiento_id' => $establecimiento->id,
                'email' => $datos['email'] ?? null,
                'telefono' => $datos['telefono'] ?? null,
                'rol' => $datos['rol'],
                'token_hash' => Invitacion::hashToken($token),
                'expira_en' => now()->addDays(7),
                'creado_por' => $autor->id,
            ]);
            $invitacion->tokenPlano = $token;

            $this->auditoria->handle($establecimiento->id, $autor->id, 'invitacion', $invitacion->id, 'invitar', null, [
                'rol' => $invitacion->rol, 'email' => $invitacion->email, 'telefono' => $invitacion->telefono,
            ]);

            return $invitacion;
        });

        if ($invitacion->email !== null) {
            Notification::route('mail', $invitacion->email)->notify(new InvitacionNotification(
                (string) $invitacion->url(), $establecimiento->nombre, $autor->nombre, $invitacion->rol,
            ));
        }

        return $invitacion;
    }
}
