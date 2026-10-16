<?php

namespace App\Domain\Establecimientos\Actions;

use App\Domain\Establecimientos\Models\Productor;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /productores/{p}: concurrencia optimista por `row_version` + auditoría (sin el CUIT en claro).
 */
final class ActualizarProductor
{
    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /** @param array<string, mixed> $datos */
    public function handle(Productor $productor, User $usuario, array $datos, ?string $establecimientoId): Productor
    {
        return DB::transaction(function () use ($productor, $usuario, $datos, $establecimientoId) {
            /** @var Productor $productor */
            $productor = Productor::query()->lockForUpdate()->findOrFail($productor->id);

            if ((int) $datos['row_version'] !== $productor->row_version) {
                throw ApiException::conflictoRowVersion(['row_version' => $productor->row_version]);
            }

            $antes = $productor->only(['tipo', 'nombre', 'contacto', 'asociaciones', 'logo_sha256']);
            $productor->fill(array_intersect_key($datos, array_flip(['tipo', 'nombre', 'contacto', 'asociaciones', 'logo_sha256'])));
            $cambioCuit = array_key_exists('cuit', $datos);
            if ($cambioCuit) {
                $productor->asignarCuit($datos['cuit']);
            }
            $productor->row_version++;
            $productor->save();

            $despues = $productor->only(['tipo', 'nombre', 'contacto', 'asociaciones', 'logo_sha256']);
            if ($cambioCuit) {
                $despues['cuit'] = '(modificado)';
            }
            $this->auditoria->handle($establecimientoId, $usuario->id, 'productor', $productor->id, 'editar', $antes, $despues);

            return $productor;
        });
    }
}
