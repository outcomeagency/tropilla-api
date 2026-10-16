<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Invitacion;
use App\Domain\Establecimientos\Models\Membresia;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Acepta una invitación vigente: crea la membresía activa del usuario logueado.
 * El token es el secreto (sirve para invitaciones por teléfono compartidas por WhatsApp):
 * no se exige que el email del usuario coincida con el invitado.
 */
final class AceptarInvitacion
{
    public function __construct(
        private readonly RegistrarCambio $registrarCambio,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function handle(string $token, User $usuario): Membresia
    {
        return DB::transaction(function () use ($token, $usuario) {
            $invitacion = Invitacion::porToken($token);
            if ($invitacion === null || $invitacion->establecimiento->archivado_en !== null) {
                throw ApiException::noEncontrado(__('La invitación no existe.'));
            }
            /** @var Invitacion $invitacion */
            $invitacion = Invitacion::query()->lockForUpdate()->findOrFail($invitacion->id);
            if (! $invitacion->vigente()) {
                throw ApiException::validacion(__('La invitación venció, fue revocada o ya se usó.'), ['token' => [__('Invitación no vigente.')]]);
            }

            $yaEs = Membresia::query()->activas()
                ->where('establecimiento_id', $invitacion->establecimiento_id)
                ->where('usuario_id', $usuario->id)->exists();
            if ($yaEs) {
                throw ApiException::validacion(__('Ya sos miembro de este establecimiento.'), ['token' => [__('Ya sos miembro de este establecimiento.')]]);
            }

            $membresia = Membresia::query()->create([
                'establecimiento_id' => $invitacion->establecimiento_id,
                'usuario_id' => $usuario->id,
                'rol' => Rol::from($invitacion->rol),
                'invitado_por' => $invitacion->creado_por,
                'aceptada_en' => now(),
            ]);
            $invitacion->forceFill(['aceptada_en' => now(), 'aceptada_por' => $usuario->id])->save();

            $this->registrarCambio->handle($invitacion->establecimiento_id, 'membresia', $membresia->id);
            $this->auditoria->handle($invitacion->establecimiento_id, $usuario->id, 'membresia', $membresia->id, 'crear', null, [
                'rol' => $membresia->rol->value, 'invitacion_id' => $invitacion->id,
            ]);

            return $membresia->load('establecimiento.productor');
        });
    }
}
