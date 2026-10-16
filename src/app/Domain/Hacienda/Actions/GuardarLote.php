<?php

namespace App\Domain\Hacienda\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Models\Lote;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alta/edición/archivo de lotes (TPA-20; también el push de maestros de TPA-43). Maestro con `row_version`.
 * La especie no se cambia (un lote no mezcla especies) y solo se archiva sin cabezas. Potrero y composición
 * no se editan acá: salen de eventos (`lotes_estado`, `lote_conteos`).
 */
final class GuardarLote
{
    private const CAMPOS = ['nombre', 'proposito', 'anonimo', 'notas'];

    public function __construct(private readonly RegistrarCambio $registrarCambio, private readonly RegistrarAuditoria $auditoria) {}

    /**
     * @param  array<string, mixed>  $datos  nombre, especie, proposito?, anonimo?, notas?, id?
     * @return array{lote: Lote, creado: bool}
     */
    public function crear(Establecimiento $est, User $usuario, array $datos): array
    {
        return DB::transaction(function () use ($est, $usuario, $datos) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            if (! empty($datos['id']) && ($existente = Lote::query()->find($datos['id']))) {
                if ($existente->establecimiento_id !== $est->id) {
                    throw ApiException::validacion(__('El id ya está en uso.'), ['id' => [__('El id ya está en uso.')]]);
                }

                return ['lote' => $existente, 'creado' => false];
            }
            if (! in_array($datos['especie'], $est->especies, true)) {
                throw ApiException::reglaBloqueante(__('El establecimiento no tiene declarada esa especie.'), ['especie' => [__('Especie no declarada.')]]);
            }
            $lote = new Lote([...array_intersect_key($datos, array_flip(self::CAMPOS)), 'establecimiento_id' => $est->id,
                'especie_codigo' => $datos['especie'], 'creado_por' => $usuario->id]);
            if (! empty($datos['id'])) {
                $lote->id = $datos['id'];
            }
            $lote->save();
            $this->registrarCambio->handle($est->id, 'lote', $lote->id, cadena: $cadena);
            $this->auditoria->handle($est->id, $usuario->id, 'lote', $lote->id, 'crear', null, [...$lote->only(self::CAMPOS), 'especie' => $lote->especie_codigo]);

            return ['lote' => $lote->refresh(), 'creado' => true];
        });
    }

    /** @param array<string, mixed> $datos */
    public function editar(Lote $lote, User $usuario, array $datos): Lote
    {
        return DB::transaction(function () use ($lote, $usuario, $datos) {
            $cadena = Cadena::bloquearDeEstablecimiento($lote->establecimiento_id);
            /** @var Lote $l */
            $l = Lote::query()->lockForUpdate()->findOrFail($lote->id);
            if ((int) $datos['row_version'] !== $l->row_version) {
                throw ApiException::conflictoRowVersion(['row_version' => $l->row_version]);
            }
            if (isset($datos['especie']) && $datos['especie'] !== $l->especie_codigo) {
                throw ApiException::reglaBloqueante(__('La especie de un lote no se cambia.'), ['especie' => [__('Inmutable.')]]);
            }
            $antes = $l->only(self::CAMPOS);
            $l->fill(array_intersect_key($datos, array_flip(self::CAMPOS)));
            if (! $l->isDirty()) {
                return $l;
            }
            $l->row_version++;
            $l->save();
            $this->registrarCambio->handle($l->establecimiento_id, 'lote', $l->id, cadena: $cadena);
            $this->auditoria->handle($l->establecimiento_id, $usuario->id, 'lote', $l->id, 'editar', $antes, $l->only(self::CAMPOS));

            return $l;
        });
    }

    public function archivar(Lote $lote, User $usuario, string $motivo): Lote
    {
        return DB::transaction(function () use ($lote, $usuario, $motivo) {
            $cadena = Cadena::bloquearDeEstablecimiento($lote->establecimiento_id);
            /** @var Lote $l */
            $l = Lote::query()->lockForUpdate()->findOrFail($lote->id);
            if ($l->archivado_en !== null) {
                return $l;
            }
            $estado = DB::table('lotes_estado')->where('lote_id', $l->id)->first();
            if ($estado && ($estado->cabezas_individuales + $estado->cabezas_anonimas) > 0) {
                throw ApiException::reglaBloqueante(__('Solo se archiva un lote sin cabezas.'));
            }
            $l->forceFill(['archivado_en' => now(), 'archivado_por' => $usuario->id, 'row_version' => $l->row_version + 1])->save();
            $this->registrarCambio->handle($l->establecimiento_id, 'lote', $l->id, 'archivar', cadena: $cadena);
            $this->auditoria->handle($l->establecimiento_id, $usuario->id, 'lote', $l->id, 'archivar', null, null, $motivo);

            return $l;
        });
    }
}
