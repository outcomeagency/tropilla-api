<?php

namespace App\Domain\Sanidad\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sanidad\AlcanceSanitario;
use App\Domain\Sanidad\Models\Tarea;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tareas (TPA-33): manual, reprogramar (queda `reprogramada`: el generador ya no le cambia las fechas), omitir con motivo,
 * y cierre/reapertura por evento (`payload.tarea_id`, lo llama `RegistrarEvento` dentro de su transacción).
 */
final class GestionarTarea
{
    private const CAMPOS = ['titulo', 'tipo_evento', 'enfermedad', 'producto_id', 'alcance', 'fecha_desde', 'fecha_hasta', 'vence_en'];

    public function __construct(
        private readonly RegistrarCambio $registrarCambio,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** @param array<string, mixed> $datos */
    public function crear(Establecimiento $est, User $usuario, array $datos): Tarea
    {
        return DB::transaction(function () use ($est, $usuario, $datos) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            if (! empty($datos['id']) && ($t = Tarea::query()->find($datos['id']))) {
                if ($t->establecimiento_id !== $est->id) {
                    throw ApiException::validacion(__('El id ya está en uso.'), ['id' => [__('El id ya está en uso.')]]);
                }

                return $t;
            }
            $t = new Tarea([...array_intersect_key($datos, array_flip(self::CAMPOS)), 'establecimiento_id' => $est->id]);
            $t->alcance = $datos['alcance'] ?? [];
            $t->cabezas_estimadas = AlcanceSanitario::cabezas($est->id, null, $t->alcance);
            if (! empty($datos['id'])) {
                $t->id = $datos['id'];
            }
            $t->save();
            $this->registrarCambio->handle($est->id, 'tarea', $t->id, cadena: $cadena);
            $this->auditoria->handle($est->id, $usuario->id, 'tarea', $t->id, 'crear', null, $t->only(self::CAMPOS));

            return $t->refresh();
        });
    }

    /** @param array<string, mixed> $datos */
    public function reprogramar(Establecimiento $est, Tarea $tarea, User $usuario, array $datos): Tarea
    {
        return DB::transaction(function () use ($est, $tarea, $usuario, $datos) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            /** @var Tarea $t */
            $t = Tarea::query()->lockForUpdate()->findOrFail($tarea->id);
            if ((int) $datos['row_version'] !== $t->row_version) {
                throw ApiException::conflictoRowVersion(['row_version' => $t->row_version]);
            }
            if ($t->estado !== 'pendiente') {
                throw ApiException::reglaBloqueante(__('Solo se reprograma una tarea pendiente.'));
            }
            $antes = $t->only(self::CAMPOS);
            $t->fill(array_intersect_key($datos, array_flip(self::CAMPOS)));
            if (($t->fecha_desde && $t->fecha_hasta && $t->fecha_desde->gt($t->fecha_hasta))) {
                throw ApiException::validacion(__('La ventana no es válida.'), ['fecha_hasta' => [__('Debe ser posterior a la fecha desde.')]]);
            }
            if (! $t->isDirty()) {
                return $t;
            }
            $t->reprogramada = $t->reprogramada || $t->isDirty(['fecha_desde', 'fecha_hasta', 'vence_en']);
            $t->row_version++;
            $t->save();
            $this->registrarCambio->handle($est->id, 'tarea', $t->id, cadena: $cadena);
            $this->auditoria->handle($est->id, $usuario->id, 'tarea', $t->id, 'reprogramar', $antes, $t->only(self::CAMPOS));

            return $t->refresh();
        });
    }

    public function omitir(Establecimiento $est, Tarea $tarea, User $usuario, string $motivo): Tarea
    {
        return DB::transaction(function () use ($est, $tarea, $usuario, $motivo) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            /** @var Tarea $t */
            $t = Tarea::query()->lockForUpdate()->findOrFail($tarea->id);
            if ($t->estado === 'omitida') {
                return $t;
            }
            if ($t->estado === 'hecha') {
                throw ApiException::reglaBloqueante(__('La tarea ya está hecha.'));
            }
            $t->forceFill(['estado' => 'omitida', 'omitida_motivo' => $motivo, 'omitida_por' => $usuario->id, 'omitida_en' => now(), 'row_version' => $t->row_version + 1])->save();
            $this->registrarCambio->handle($est->id, 'tarea', $t->id, cadena: $cadena);
            $this->auditoria->handle($est->id, $usuario->id, 'tarea', $t->id, 'omitir', null, null, $motivo);

            return $t;
        });
    }

    /**
     * Cierre por evento (cualquier canal): alta con `tarea_id` → hecha; anulación del evento que la cerró → pendiente otra vez.
     * Se llama dentro de la transacción de `RegistrarEvento` (cadena ya bloqueada).
     */
    public function porEvento(Evento $evento, Cadena $cadena): void
    {
        if ($evento->accion === 'anulacion') {
            $t = Tarea::query()->where('establecimiento_id', $evento->establecimiento_id)->where('evento_raiz_id', $evento->evento_raiz_id)->where('estado', 'hecha')->first();
            if ($t) {
                $t->forceFill(['estado' => 'pendiente', 'evento_raiz_id' => null, 'hecha_en' => null, 'row_version' => $t->row_version + 1])->save();
                $this->registrarCambio->handle($evento->establecimiento_id, 'tarea', $t->id, cadena: $cadena);
            }

            return;
        }
        $tareaId = $evento->payload['tarea_id'] ?? null;
        if ($tareaId === null) {
            return;
        }
        $t = Tarea::query()->where('id', $tareaId)->where('establecimiento_id', $evento->establecimiento_id)->first();
        if ($t && $t->estado !== 'hecha') {
            $t->forceFill(['estado' => 'hecha', 'evento_raiz_id' => $evento->evento_raiz_id, 'hecha_en' => now(), 'row_version' => $t->row_version + 1])->save();
            $this->registrarCambio->handle($evento->establecimiento_id, 'tarea', $t->id, cadena: $cadena);
        }
    }
}
