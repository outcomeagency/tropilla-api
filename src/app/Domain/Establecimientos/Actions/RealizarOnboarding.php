<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Models\Productor;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * POST /onboarding (TPA-10): alta de productor + primer establecimiento; el usuario queda dueño.
 */
final class RealizarOnboarding
{
    public function __construct(
        private readonly CrearEstablecimiento $crearEstablecimiento,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** @param array{productor: array<string, mixed>, establecimiento: array<string, mixed>} $datos */
    public function handle(User $usuario, array $datos): Establecimiento
    {
        return DB::transaction(function () use ($usuario, $datos) {
            $p = $datos['productor'];
            $productor = new Productor([
                'tipo' => $p['tipo'],
                'nombre' => $p['nombre'],
                'contacto' => $p['contacto'] ?? [],
                'asociaciones' => $p['asociaciones'] ?? [],
                'logo_sha256' => $p['logo_sha256'] ?? null,
                'creado_por' => $usuario->id,
            ]);
            $productor->asignarCuit($p['cuit'] ?? null);
            $productor->save();

            $establecimiento = $this->crearEstablecimiento->handle($productor, $usuario, $datos['establecimiento']);
            $this->auditoria->handle($establecimiento->id, $usuario->id, 'productor', $productor->id, 'crear', null, [
                'tipo' => $productor->tipo, 'nombre' => $productor->nombre,
            ]);

            return $establecimiento;
        });
    }
}
