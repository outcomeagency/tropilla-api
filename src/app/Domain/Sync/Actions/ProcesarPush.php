<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Documentos\Actions\RegistrarDocumentoVersion;
use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Actions\GuardarLote;
use App\Domain\Hacienda\Actions\GuardarPotrero;
use App\Domain\Hacienda\Eventos\EventoRechazado;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Domain\Hacienda\Models\Lote;
use App\Domain\Hacienda\Models\Potrero;
use App\Domain\Integridad\Models\Cadena;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * POST /sync/push (sync-e-integridad.md §7.3): procesa los ítems del outbox **en orden**, cada uno en su
 * propia transacción (un ítem fallido no tumba el lote) y devuelve un resultado por ítem:
 * `aceptado` | `duplicado` | `en_revision` | `rechazado` (+ `codigo`, `reintentable`).
 */
final class ProcesarPush
{
    public function __construct(
        private readonly RegistrarEvento $registrarEvento,
        private readonly GuardarPotrero $potreros,
        private readonly GuardarLote $lotes,
    ) {}

    /**
     * @param  array{rol: Rol, post_revocacion: bool, revocada_en: ?Carbon}  $acceso
     * @return list<array<string, mixed>>
     */
    public function handle(Establecimiento $est, User $usuario, stdClass $in, array $acceso): array
    {
        $resultados = [];
        foreach ($in->items as $item) {
            $id = (string) ($item->id ?? ($item->sobre->id ?? ''));
            try {
                $resultados[] = match ($item->clase ?? null) {
                    'evento' => $this->evento($est, $usuario, $item, $acceso),
                    'maestro' => $this->maestro($est, $usuario, $item, $acceso['rol']),
                    'documento_version' => $this->documento($est, $usuario, $item, $acceso),
                    default => throw new EventoRechazado('esquema_invalido', 'Clase de ítem desconocida.'),
                };
            } catch (EventoRechazado $e) {
                $detalle = $e->errors ? ' '.json_encode($e->errors, JSON_UNESCAPED_UNICODE) : '';
                $resultados[] = ['id' => $id, 'estado' => 'rechazado', 'codigo' => $e->codigo, 'mensaje' => mb_substr($e->getMessage().$detalle, 0, 1000),
                    'reintentable' => $e->reintentable()];
            } catch (ApiException $e) {
                $resultados[] = ['id' => $id, 'estado' => 'rechazado', 'codigo' => $e->codigo, 'mensaje' => $e->getMessage(), 'reintentable' => false];
            } catch (Throwable $e) {
                report($e);
                Log::error('sync push: error inesperado', ['id' => $id, 'error' => $e->getMessage()]);
                $resultados[] = ['id' => $id, 'estado' => 'rechazado', 'codigo' => 'error_interno', 'mensaje' => 'Error del servidor; se reintenta.', 'reintentable' => true];
            }
        }
        DB::table('dispositivos')->where('id', $in->dispositivo_id)->update(['ultimo_push_en' => now()]);

        return array_map(fn ($r) => array_filter($r, fn ($v) => $v !== null), $resultados);
    }

    /** @param array{rol: Rol, post_revocacion: bool, revocada_en: ?Carbon} $acceso */
    private function evento(Establecimiento $est, User $usuario, stdClass $item, array $acceso): array
    {
        if (! isset($item->sobre) || ! $item->sobre instanceof stdClass || ! isset($item->huella)) {
            throw new EventoRechazado('esquema_invalido', 'El ítem de evento lleva sobre y huella.');
        }
        if ($acceso['post_revocacion'] && Carbon::parse((string) ($item->sobre->creado_en ?? 'now'))->gt($acceso['revocada_en'])) {
            throw new EventoRechazado('establecimiento_cerrado', 'El acceso al establecimiento fue revocado antes de cargar esto.');
        }
        $r = $this->registrarEvento->handle($est, $usuario, $item->sobre, [
            'canal' => 'push', 'huella' => (string) $item->huella, 'rol' => $acceso['rol'], 'post_revocacion' => $acceso['post_revocacion'],
        ]);

        return [
            'id' => $r->evento->id,
            'estado' => $r->estado,
            'seq' => $r->seq,
            'eslabon' => $r->eslabon,
            'registrado_en' => Formato::fechaHora($r->evento->registrado_en),
            'observaciones' => $r->observaciones,
            'codigo' => $r->estado === 'en_revision' ? 'conflicto_version' : null,
            'revision_id' => $r->revisionId,
            'version_vigente' => $r->versionVigente,
        ];
    }

    /** Versión de documento (TPA-36): misma puerta que la web (`RegistrarDocumentoVersion`). @param array{rol: Rol, post_revocacion: bool, revocada_en: ?Carbon} $acceso */
    private function documento(Establecimiento $est, User $usuario, stdClass $item, array $acceso): array
    {
        if (! isset($item->sobre) || ! $item->sobre instanceof stdClass || ! isset($item->huella)) {
            throw new EventoRechazado('esquema_invalido', 'El ítem de documento lleva sobre y huella.');
        }
        if ($acceso['post_revocacion'] && Carbon::parse((string) ($item->sobre->creado_en ?? 'now'))->gt($acceso['revocada_en'])) {
            throw new EventoRechazado('establecimiento_cerrado', 'El acceso al establecimiento fue revocado antes de cargar esto.');
        }
        $r = app(RegistrarDocumentoVersion::class)->handle($est, $usuario, $item->sobre, ['canal' => 'push', 'huella' => (string) $item->huella, 'rol' => $acceso['rol']]);

        return ['id' => $r->versionId, 'estado' => $r->estado, 'seq' => $r->seq, 'eslabon' => $r->eslabon, 'registrado_en' => $r->registradoEn,
            'codigo' => $r->estado === 'en_revision' ? 'conflicto_version' : null, 'revision_id' => $r->revisionId];
    }

    /**
     * Maestros (lote, potrero) con concurrencia optimista: `base_row_version` 0 = alta offline; si no coincide con
     * la actual, gana el servidor y el cambio del cliente queda en una revisión `maestro_en_conflicto` (§7.5).
     */
    private function maestro(Establecimiento $est, User $usuario, stdClass $item, Rol $rol): array
    {
        if (! $rol->puede('maestros.editar')) {
            throw new EventoRechazado('sin_permiso', 'Tu rol no permite editar maestros.');
        }
        $entidad = $item->entidad ?? null;
        if (! in_array($entidad, ['lote', 'potrero'], true)) {
            throw new EventoRechazado('tipo_desconocido', "La entidad {$entidad} todavía no se sincroniza.");
        }
        $id = (string) ($item->id ?? '');
        if (! preg_match('/^[0-9a-f-]{36}$/', $id) || ! isset($item->datos) || ! $item->datos instanceof stdClass || ! isset($item->base_row_version)) {
            throw new EventoRechazado('esquema_invalido', 'El maestro lleva id, base_row_version y datos.');
        }
        $datos = json_decode(json_encode($item->datos), true);
        $modelo = $entidad === 'lote' ? Lote::query()->find($id) : Potrero::query()->find($id);
        if ($modelo !== null && $modelo->establecimiento_id !== $est->id) {
            throw new EventoRechazado('id_reutilizado', 'Ese id pertenece a otro establecimiento.');
        }

        if ((int) $item->base_row_version === 0) {
            if ($modelo !== null) {
                return ['id' => $id, 'estado' => 'duplicado', 'row_version' => $modelo->row_version];
            }
            $this->validarMaestro($entidad, $datos, false);
            $nuevo = $entidad === 'lote'
                ? $this->lotes->crear($est, $usuario, ['id' => $id, ...$datos])['lote']
                : $this->potreros->crear($est, $usuario, ['id' => $id, ...$datos])['potrero'];

            return ['id' => $id, 'estado' => 'aceptado', 'row_version' => $nuevo->row_version];
        }

        if ($modelo === null) {
            throw new EventoRechazado('dependencia_faltante', 'El maestro todavía no está en el servidor.');
        }
        if ((int) $item->base_row_version !== $modelo->row_version) {
            $revisionId = (string) Str::uuid7();
            DB::transaction(function () use ($est, $entidad, $id, $item, $datos, $modelo, $revisionId) {
                DB::table('revisiones')->insert([
                    'id' => $revisionId, 'establecimiento_id' => $est->id, 'tipo' => 'maestro_en_conflicto', 'entidad_tipo' => $entidad,
                    'entidad_id' => $id, 'detalle' => json_encode(['base_row_version' => $item->base_row_version, 'row_version_actual' => $modelo->row_version,
                        'cambios_cliente' => $datos, 'actual' => $modelo->only(array_keys($datos))]),
                    'estado' => 'abierta', 'created_at' => now(), 'updated_at' => now(),
                ]);
                app(RegistrarCambio::class)->handle($est->id, 'revision', $revisionId, cadena: Cadena::bloquearDeEstablecimiento($est->id));
            });

            return ['id' => $id, 'estado' => 'en_revision', 'codigo' => 'conflicto_row_version', 'revision_id' => $revisionId, 'row_version' => $modelo->row_version];
        }
        $this->validarMaestro($entidad, $datos, true);
        $datos['row_version'] = $modelo->row_version;
        $editado = $entidad === 'lote' ? $this->lotes->editar($modelo, $usuario, $datos) : $this->potreros->editar($modelo, $usuario, $datos);

        return ['id' => $id, 'estado' => 'aceptado', 'row_version' => $editado->row_version];
    }

    /** @param array<string, mixed> $datos */
    private function validarMaestro(string $entidad, array $datos, bool $parcial): void
    {
        $reglas = $entidad === 'lote'
            ? ['nombre' => [$parcial ? 'sometimes' : 'required', 'string', 'max:80'], 'especie' => [$parcial ? 'sometimes' : 'required', 'exists:especies,codigo'],
                'proposito' => ['sometimes', 'nullable', 'in:cria,recria,engorde,reproductores,venta,descarte'], 'anonimo' => ['sometimes', 'boolean'], 'notas' => ['sometimes', 'nullable', 'string', 'max:2000']]
            : ['nombre' => [$parcial ? 'sometimes' : 'required', 'string', 'max:80'], 'superficie_ha' => ['sometimes', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]*)(\.[0-9]*[1-9])?$/'],
                'ambiente' => ['sometimes', 'nullable', 'string'], 'recurso_forrajero' => ['sometimes', 'nullable', 'string'],
                'agua' => ['sometimes', 'boolean'], 'revolcadero' => ['sometimes', 'boolean'], 'notas' => ['sometimes', 'nullable', 'string', 'max:2000']];
        $v = validator($datos, $reglas);
        if ($v->fails()) {
            throw new EventoRechazado('esquema_invalido', 'Datos del maestro inválidos.', $v->errors()->toArray());
        }
    }
}
