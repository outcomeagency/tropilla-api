<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Membresia;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cambiar rol, revocar acceso y transferir titularidad (TPA-11, modelo-datos.md §2.5 y §13.1 ¹).
 * Nada se borra: cambiar rol = revocar + crear; la historia queda en `membresias` y `auditoria`.
 * El dueño no se puede degradar ni revocar (ni siquiera por sí mismo): primero se transfiere la titularidad.
 */
final class GestionarMembresias
{
    public function __construct(
        private readonly RegistrarCambio $registrarCambio,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function cambiarRol(Establecimiento $establecimiento, Membresia $membresia, User $autor, string $rol, ?string $motivo): Membresia
    {
        return DB::transaction(function () use ($establecimiento, $membresia, $autor, $rol, $motivo) {
            $membresia = $this->bloquearActiva($membresia);
            $this->protegerDueno($membresia);
            if ($membresia->rol->value === $rol) {
                return $membresia;
            }

            $this->revocar($membresia, $autor, $motivo ?: __('Cambio de rol'));
            $nueva = Membresia::query()->create([
                'establecimiento_id' => $establecimiento->id,
                'usuario_id' => $membresia->usuario_id,
                'rol' => Rol::from($rol),
                'invitado_por' => $autor->id,
                'aceptada_en' => now(),
            ]);
            $this->registrarCambio->handle($establecimiento->id, 'membresia', $nueva->id);
            $this->auditoria->handle($establecimiento->id, $autor->id, 'membresia', $nueva->id, 'editar',
                ['rol' => $membresia->rol->value, 'membresia_id' => $membresia->id], ['rol' => $rol], $motivo);

            return $nueva->load('usuario');
        });
    }

    public function revocarAcceso(Establecimiento $establecimiento, Membresia $membresia, User $autor, string $motivo): void
    {
        DB::transaction(function () use ($establecimiento, $membresia, $autor, $motivo) {
            $membresia = $this->bloquearActiva($membresia);
            $this->protegerDueno($membresia);
            $this->revocar($membresia, $autor, $motivo);
            $this->auditoria->handle($establecimiento->id, $autor->id, 'membresia', $membresia->id, 'revocar_membresia',
                ['rol' => $membresia->rol->value, 'estado' => 'activa'], ['estado' => 'revocada'], $motivo);
        });
    }

    /**
     * Solo el dueño. El destinatario tiene que ser miembro activo; el dueño saliente queda como admin.
     */
    public function transferirTitularidad(Establecimiento $establecimiento, User $dueno, string $usuarioId, string $motivo): void
    {
        DB::transaction(function () use ($establecimiento, $dueno, $usuarioId, $motivo) {
            $actual = Membresia::query()->activas()->where('establecimiento_id', $establecimiento->id)
                ->where('rol', Rol::Dueno)->lockForUpdate()->firstOrFail();
            if ($usuarioId === $dueno->id) {
                throw ApiException::validacion(__('Ya sos el dueño.'), ['usuario_id' => [__('Ya sos el dueño.')]]);
            }
            $destino = Membresia::query()->activas()->where('establecimiento_id', $establecimiento->id)
                ->where('usuario_id', $usuarioId)->lockForUpdate()->first();
            if ($destino === null) {
                throw ApiException::validacion(__('La persona tiene que ser miembro del establecimiento.'), ['usuario_id' => [__('No es miembro del establecimiento.')]]);
            }

            $this->revocar($actual, $dueno, $motivo);
            $this->revocar($destino, $dueno, $motivo);
            foreach ([[$destino->usuario_id, Rol::Dueno], [$actual->usuario_id, Rol::Admin]] as [$usuario, $rol]) {
                $m = Membresia::query()->create([
                    'establecimiento_id' => $establecimiento->id, 'usuario_id' => $usuario, 'rol' => $rol,
                    'invitado_por' => $dueno->id, 'aceptada_en' => now(),
                ]);
                $this->registrarCambio->handle($establecimiento->id, 'membresia', $m->id);
            }
            $this->auditoria->handle($establecimiento->id, $dueno->id, 'establecimiento', $establecimiento->id, 'transferir_titularidad',
                ['dueno' => $actual->usuario_id], ['dueno' => $destino->usuario_id], $motivo);
        });
    }

    private function bloquearActiva(Membresia $membresia): Membresia
    {
        /** @var Membresia $m */
        $m = Membresia::query()->lockForUpdate()->findOrFail($membresia->id);
        if (! $m->activa()) {
            throw ApiException::validacion(__('La membresía ya fue revocada.'), ['membresia' => [__('Membresía no activa.')]]);
        }

        return $m;
    }

    private function protegerDueno(Membresia $membresia): void
    {
        if ($membresia->rol === Rol::Dueno) {
            throw ApiException::reglaBloqueante(__('Al dueño no se le cambia el rol ni se le revoca el acceso: primero transferí la titularidad.'));
        }
    }

    private function revocar(Membresia $membresia, User $autor, string $motivo): void
    {
        $membresia->forceFill([
            'estado' => 'revocada', 'revocada_en' => now(), 'revocada_por' => $autor->id, 'motivo_revocacion' => $motivo,
        ])->save();
        $this->registrarCambio->handle($membresia->establecimiento_id, 'membresia', $membresia->id);
    }
}
