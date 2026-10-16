<?php

namespace App\Domain\Sanidad\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sanidad\Models\ProductoSanitario;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alta, edición y archivo de productos sanitarios (TPA-31). Mismo patrón que los maestros de hacienda:
 * id del cliente idempotente, `row_version` (409), auditoría y `cambios` para el pull de la app.
 */
final class GuardarProductoSanitario
{
    public function __construct(
        private readonly RegistrarCambio $registrarCambio,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     * @return array{producto: ProductoSanitario, creado: bool}
     */
    public function crear(Establecimiento $establecimiento, User $usuario, array $datos): array
    {
        return DB::transaction(function () use ($establecimiento, $usuario, $datos) {
            $cadena = Cadena::bloquearDeEstablecimiento($establecimiento->id);

            if (! empty($datos['id']) && ($existente = ProductoSanitario::query()->find($datos['id']))) {
                if ($existente->establecimiento_id !== $establecimiento->id) {
                    throw ApiException::validacion(__('El id ya está en uso.'), ['id' => [__('El id ya está en uso.')]]);
                }

                return ['producto' => $existente, 'creado' => false];
            }
            $this->nombreLibre($establecimiento->id, $datos['nombre_comercial']);

            $p = new ProductoSanitario([...array_intersect_key($datos, array_flip(ProductoSanitario::CAMPOS)), 'establecimiento_id' => $establecimiento->id]);
            if (! empty($datos['id'])) {
                $p->id = $datos['id'];
            }
            $p->save();

            $this->registrarCambio->handle($establecimiento->id, 'producto_sanitario', $p->id, cadena: $cadena);
            $this->auditoria->handle($establecimiento->id, $usuario->id, 'producto_sanitario', $p->id, 'crear', null, $p->only(ProductoSanitario::CAMPOS));

            return ['producto' => $p->refresh(), 'creado' => true];
        });
    }

    /** @param array<string, mixed> $datos */
    public function editar(ProductoSanitario $producto, User $usuario, array $datos): ProductoSanitario
    {
        return DB::transaction(function () use ($producto, $usuario, $datos) {
            $cadena = Cadena::bloquearDeEstablecimiento($producto->establecimiento_id);
            /** @var ProductoSanitario $p */
            $p = ProductoSanitario::query()->lockForUpdate()->findOrFail($producto->id);
            if ((int) $datos['row_version'] !== $p->row_version) {
                throw ApiException::conflictoRowVersion(['row_version' => $p->row_version]);
            }
            if ($p->archivado_en !== null) {
                throw ApiException::reglaBloqueante(__('El producto está archivado.'));
            }
            if (isset($datos['nombre_comercial']) && mb_strtolower($datos['nombre_comercial']) !== mb_strtolower($p->nombre_comercial)) {
                $this->nombreLibre($p->establecimiento_id, $datos['nombre_comercial'], $p->id);
            }

            $antes = $p->only(ProductoSanitario::CAMPOS);
            $p->fill(array_intersect_key($datos, array_flip(ProductoSanitario::CAMPOS)));
            if (! $p->isDirty()) {
                return $p;
            }
            $p->row_version++;
            $p->save();

            $this->registrarCambio->handle($p->establecimiento_id, 'producto_sanitario', $p->id, cadena: $cadena);
            $this->auditoria->handle($p->establecimiento_id, $usuario->id, 'producto_sanitario', $p->id, 'editar', $antes, $p->only(ProductoSanitario::CAMPOS));

            return $p->refresh();
        });
    }

    public function archivar(ProductoSanitario $producto, User $usuario, string $motivo): ProductoSanitario
    {
        return DB::transaction(function () use ($producto, $usuario, $motivo) {
            $cadena = Cadena::bloquearDeEstablecimiento($producto->establecimiento_id);
            /** @var ProductoSanitario $p */
            $p = ProductoSanitario::query()->lockForUpdate()->findOrFail($producto->id);
            if ($p->archivado_en !== null) {
                return $p;
            }
            $p->forceFill(['archivado_en' => now(), 'archivado_por' => $usuario->id, 'row_version' => $p->row_version + 1])->save();
            $this->registrarCambio->handle($p->establecimiento_id, 'producto_sanitario', $p->id, 'archivar', cadena: $cadena);
            $this->auditoria->handle($p->establecimiento_id, $usuario->id, 'producto_sanitario', $p->id, 'archivar', null, null, $motivo);

            return $p;
        });
    }

    private function nombreLibre(string $establecimientoId, string $nombre, ?string $excepto = null): void
    {
        $usado = ProductoSanitario::query()->where('establecimiento_id', $establecimientoId)->whereNull('archivado_en')
            ->whereRaw('lower(nombre_comercial) = lower(?)', [$nombre])
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))->exists();
        if ($usado) {
            throw ApiException::validacion(__('Ya hay un producto con ese nombre.'), ['nombre_comercial' => [__('Ya hay un producto con ese nombre.')]]);
        }
    }
}
