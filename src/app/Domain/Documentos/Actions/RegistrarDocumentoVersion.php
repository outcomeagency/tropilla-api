<?php

namespace App\Domain\Documentos\Actions;

use App\Domain\Documentos\Archivos;
use App\Domain\Documentos\ResultadoDocumento;
use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\EventoRechazado;
use App\Domain\Integridad\Actions\RegistrarEnCadena;
use App\Domain\Integridad\CanonicalizacionInvalida;
use App\Domain\Integridad\CanonicalJson;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use stdClass;

/**
 * Única puerta de las versiones de documento (TPA-36, sync §3.2): web (el servidor arma el sobre) y push (sobre firmado por el
 * teléfono). Canonicaliza y hashea, valida estructura/pertenencia, idempotencia por id, permisos (fiscal exige
 * `documentos.fiscal`; encargado/asesor solo versionan lo que crearon), cadena de versiones (`hash_anterior`), vínculos
 * existentes y archivos (≥ 1 salvo revocación; quedan `pendiente` hasta que suban). Inserta la evidencia **append-only**,
 * encadena el eslabón y proyecta la cabecera `documentos` + `documento_vinculos`. Nueva versión nunca borra la anterior.
 * Concurrencia: dos versiones sobre la misma base → la segunda queda `en_revision` (push) o 409 (web).
 */
final class RegistrarDocumentoVersion
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const ACCIONES = ['alta', 'nueva_version', 'correccion_metadatos', 'revocacion'];

    public const PRIVACIDADES = ['fiscal', 'sanitario', 'comercial', 'general'];

    public const VINCULOS = ['establecimiento' => 'establecimientos', 'animal' => 'animales', 'lote' => 'lotes', 'evento' => 'eventos',
        'potrero' => 'potreros', 'operacion' => 'operaciones', 'contrato' => 'contratos'];

    public function __construct(
        private readonly RegistrarEnCadena $registrarEnCadena,
        private readonly RegistrarCambio $registrarCambio,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** @param array{canal: 'web'|'push', huella?: ?string, rol?: Rol} $op */
    public function handle(Establecimiento $est, User $autor, stdClass $s, array $op): ResultadoDocumento
    {
        try {
            $huella = CanonicalJson::huella($s);
        } catch (CanonicalizacionInvalida $e) {
            throw new EventoRechazado('canonicalizacion', $e->getMessage());
        }
        if (! empty($op['huella']) && ! hash_equals($huella, (string) $op['huella'])) {
            throw new EventoRechazado('huella_invalida', 'La huella recalculada no coincide.');
        }
        $this->estructura($s, $est, $autor, $op['canal']);
        if ($dup = $this->duplicado($s->id, $huella)) {
            return $dup;
        }

        return DB::transaction(function () use ($est, $autor, $s, $op, $huella) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            if ($dup = $this->duplicado($s->id, $huella)) {
                return $dup;
            }
            $doc = DB::table('documentos')->where('id', $s->documento_id)->lockForUpdate()->first();
            if ($doc !== null && $doc->establecimiento_id !== $est->id) {
                throw new EventoRechazado('id_reutilizado', 'Ese documento pertenece a otro establecimiento.');
            }

            // Permisos por rol (fiscal aparte; encargado/asesor solo versionan lo propio).
            $puede = fn (string $ab) => isset($op['rol']) ? $op['rol']->puede($ab) : Gate::forUser($autor)->allows($ab, $est);
            if (! $puede($s->accion === 'alta' ? 'documentos.crear' : 'documentos.versionar')) {
                throw new EventoRechazado('sin_permiso', 'Tu rol no permite cargar o versionar documentos.');
            }
            if (($s->privacidad === 'fiscal' || $doc?->privacidad === 'fiscal') && ! $puede('documentos.fiscal')) {
                throw new EventoRechazado('sin_permiso', 'Los documentos fiscales son solo para dueño y administrador.');
            }
            if ($doc !== null && $s->accion !== 'alta' && ! $puede('documentos.fiscal') && $doc->creado_por !== $autor->id) {
                throw new EventoRechazado('sin_permiso', 'Solo podés versionar los documentos que cargaste.');
            }

            // Tipo y privacidad (datos de catálogo).
            $tipo = DB::table('tipos_documento')->where('codigo', $s->tipo)->first();
            if ($tipo === null) {
                throw new EventoRechazado('esquema_invalido', 'Tipo de documento desconocido.', ['tipo' => ['No está en el catálogo.']]);
            }
            if (($tipo->privacidad_default === 'fiscal' || $doc?->privacidad === 'fiscal') && $s->privacidad !== 'fiscal') {
                throw new EventoRechazado('regla_bloqueante', 'Un documento fiscal es siempre fiscal.', ['privacidad' => ['fiscal']]);
            }

            // Cadena de versiones.
            $enRevision = false;
            $base = null;
            if ($s->version === 1) {
                if ($doc !== null) {
                    throw new EventoRechazado('id_reutilizado', 'El documento ya existe: usá una nueva versión.');
                }
            } else {
                if ($doc === null) {
                    throw new EventoRechazado('dependencia_faltante', 'El documento todavía no está en el servidor.');
                }
                $base = DB::table('documento_versiones')->where('documento_id', $doc->id)->where('version', $s->version - 1)->where('en_revision', false)->first();
                if ($base === null) {
                    throw new EventoRechazado('dependencia_faltante', 'La versión anterior todavía no está en el servidor.');
                }
                if ($base->hash !== $s->hash_anterior) {
                    throw new EventoRechazado('esquema_invalido', 'hash_anterior no es la huella de la versión anterior.', ['hash_anterior' => ['No coincide.']]);
                }
                if ((int) $doc->version_vigente !== $s->version - 1) {
                    if ($op['canal'] === 'web') {
                        throw new EventoRechazado('version_no_vigente', 'El documento ya tiene una versión más nueva.');
                    }
                    $enRevision = true;
                } elseif ($doc->estado === 'revocado') {
                    throw new EventoRechazado('regla_bloqueante', 'El documento está revocado.');
                }
            }

            // Vínculos y archivos.
            foreach ((array) ($s->vinculos ?? []) as $i => $v) {
                $tabla = self::VINCULOS[$v->tipo];
                $q = DB::table($tabla)->where('id', $v->id);
                if ($tabla !== 'establecimientos') {
                    $q->where('establecimiento_id', $est->id);
                } elseif ($v->id !== $est->id) {
                    throw new EventoRechazado('regla_bloqueante', 'El vínculo es de otro establecimiento.', ["vinculos.{$i}" => [$v->id]]);
                }
                if (! DB::getSchemaBuilder()->hasTable($tabla) || ! $q->exists()) {
                    throw new EventoRechazado('dependencia_faltante', "El {$v->tipo} vinculado todavía no está en el servidor.", ["vinculos.{$i}" => [$v->id]]);
                }
            }
            foreach ((array) ($s->archivos ?? []) as $a) {
                Archivos::referenciar($a->sha256, $a->bytes, $a->mime, $autor->id);
            }

            // Evidencia (append-only) + eslabón.
            $registro = $this->registrarEnCadena->handle($cadena, 'documento_version', $s->id, $huella);
            if ($doc === null) {
                DB::table('documentos')->insert($this->cabecera($est, $s, $autor) + ['id' => $s->documento_id, 'creado_por' => $autor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('documento_versiones')->insert([
                'id' => $s->id, 'documento_id' => $s->documento_id, 'establecimiento_id' => $est->id, 'version' => $s->version, 'accion' => $s->accion,
                'motivo' => $s->motivo ?? null, 'tipo_codigo' => $s->tipo, 'privacidad' => $s->privacidad, 'titulo' => $s->titulo,
                'emisor_nombre' => $s->emisor->nombre ?? null, 'emisor_identificacion' => $s->emisor->identificacion ?? null,
                'emitido_en' => $s->emitido_en ?? null, 'vence_en' => $s->vence_en ?? null, 'vinculos' => json_encode($s->vinculos ?? []),
                'autor_id' => $autor->id, 'dispositivo_id' => $s->dispositivo_id ?? null, 'creado_en' => Carbon::parse($s->creado_en), 'registrado_en' => $registro->registrado_en,
                'en_revision' => $enRevision, 'sobre' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'hash' => $huella, 'hash_anterior' => $s->hash_anterior ?? null, 'registro_integridad_id' => $registro->id,
            ]);
            foreach (array_values((array) ($s->archivos ?? [])) as $i => $a) {
                DB::table('documento_version_archivos')->insert(['documento_version_id' => $s->id, 'orden' => $i + 1, 'archivo_sha256' => $a->sha256]);
            }

            $revisionId = null;
            if ($enRevision) {
                $revisionId = (string) Str::uuid7();
                DB::table('revisiones')->insert(['id' => $revisionId, 'establecimiento_id' => $est->id, 'tipo' => 'documento_en_conflicto', 'entidad_tipo' => 'documento_version',
                    'entidad_id' => $s->id, 'detalle' => json_encode(['documento_id' => $doc->id, 'version_vigente' => (int) $doc->version_vigente, 'version_enviada' => $s->version]),
                    'estado' => 'abierta', 'created_at' => now(), 'updated_at' => now()]);
                $this->registrarCambio->handle($est->id, 'revision', $revisionId, cadena: $cadena);
            } else {
                if ($doc !== null) {
                    DB::table('documentos')->where('id', $doc->id)->update($this->cabecera($est, $s, $autor) + ['row_version' => $doc->row_version + 1, 'updated_at' => now(),
                        // una revocación también despublica
                        ...($s->accion === 'revocacion' ? ['visibilidad' => 'privado'] : [])]);
                }
                DB::table('documento_vinculos')->where('documento_id', $s->documento_id)->delete();
                foreach ((array) ($s->vinculos ?? []) as $v) {
                    DB::table('documento_vinculos')->insert(['documento_id' => $s->documento_id, 'entidad_tipo' => $v->tipo, 'entidad_id' => $v->id, 'establecimiento_id' => $est->id]);
                }
            }
            $this->registrarCambio->handle($est->id, 'documento', $s->documento_id, visibilidad: self::visibilidadSync($s->privacidad), cadena: $cadena);
            $this->auditoria->handle($est->id, $autor->id, 'documento', $s->documento_id, $s->accion, null, ['version' => $s->version, 'titulo' => $s->titulo], $s->motivo ?? null);

            return new ResultadoDocumento($enRevision ? 'en_revision' : 'aceptado', $s->id, $s->documento_id, $registro->seq, $registro->eslabon,
                Formato::fechaHora($registro->registrado_en), $revisionId);
        });
    }

    /** `cambios.visibilidad` según la privacidad (el pull filtra comercial/fiscal por rol). */
    public static function visibilidadSync(string $privacidad): string
    {
        return match ($privacidad) {
            'fiscal' => 'fiscal',
            'comercial' => 'comercial',
            default => 'general',
        };
    }

    /** @return array<string, mixed> */
    private function cabecera(Establecimiento $est, stdClass $s, User $autor): array
    {
        return ['establecimiento_id' => $est->id, 'version_vigente' => $s->version, 'tipo_codigo' => $s->tipo, 'privacidad' => $s->privacidad, 'titulo' => $s->titulo,
            'emisor_nombre' => $s->emisor->nombre ?? null, 'emitido_en' => $s->emitido_en ?? null, 'vence_en' => $s->vence_en ?? null,
            'estado' => $s->accion === 'revocacion' ? 'revocado' : 'vigente'];
    }

    private function duplicado(string $id, string $huella): ?ResultadoDocumento
    {
        $v = DB::table('documento_versiones')->where('id', $id)->first();
        if ($v === null) {
            return null;
        }
        if (! hash_equals($v->hash, $huella)) {
            throw new EventoRechazado('id_reutilizado', 'Ese id ya existe con otro contenido.');
        }
        $r = DB::table('registros_integridad')->where('id', $v->registro_integridad_id)->first();

        return new ResultadoDocumento('duplicado', $v->id, $v->documento_id, (int) $r->seq, $r->eslabon, Formato::fechaHora(Carbon::parse($v->registrado_en)));
    }

    private function estructura(stdClass $s, Establecimiento $est, User $autor, string $canal): void
    {
        $err = [];
        foreach (['esquema', 'id', 'documento_id', 'establecimiento_id', 'version', 'accion', 'tipo', 'privacidad', 'titulo', 'creado_en', 'autor_id'] as $c) {
            if (! isset($s->{$c})) {
                $err[$c][] = "Falta {$c}.";
            }
        }
        if ($err !== []) {
            throw new EventoRechazado('esquema_invalido', 'El sobre está incompleto.', $err);
        }
        if ($s->esquema !== 'tropilla.documento_version/1') {
            $err['esquema'][] = 'Esquema de sobre desconocido.';
        }
        foreach (['id', 'documento_id', 'establecimiento_id', 'autor_id'] as $c) {
            if (! is_string($s->{$c}) || ! preg_match(self::UUID, $s->{$c})) {
                $err[$c][] = 'UUID inválido.';
            }
        }
        if (! is_int($s->version) || $s->version < 1) {
            $err['version'][] = 'Entero ≥ 1.';
        }
        if (! in_array($s->accion, self::ACCIONES, true)) {
            $err['accion'][] = 'Acción inválida.';
        } elseif (($s->accion === 'alta') !== ($s->version === 1)) {
            $err['version'][] = 'El alta es la versión 1 y solo la versión 1 es alta.';
        }
        if (is_int($s->version) && $s->version > 1 && (! isset($s->hash_anterior) || ! preg_match('/^[0-9a-f]{64}$/', (string) $s->hash_anterior) || trim((string) ($s->motivo ?? '')) === '')) {
            $err['motivo'][] = 'Las versiones > 1 llevan hash_anterior y motivo.';
        }
        if (! in_array($s->privacidad, self::PRIVACIDADES, true)) {
            $err['privacidad'][] = 'Privacidad inválida.';
        }
        if (! is_string($s->titulo) || trim($s->titulo) === '' || mb_strlen($s->titulo) > 200) {
            $err['titulo'][] = 'Texto de 1 a 200 caracteres.';
        }
        foreach (['emitido_en', 'vence_en'] as $c) {
            if (isset($s->{$c}) && (! is_string($s->{$c}) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $s->{$c}))) {
                $err[$c][] = 'Fecha YYYY-MM-DD.';
            }
        }
        if (isset($s->emitido_en, $s->vence_en) && is_string($s->vence_en) && $s->vence_en < $s->emitido_en) {
            $err['vence_en'][] = 'No puede ser anterior a la emisión.';
        }
        if (isset($s->emisor) && ! $s->emisor instanceof stdClass) {
            $err['emisor'][] = 'Objeto {nombre, identificacion}.';
        }
        if (! is_string($s->creado_en) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $s->creado_en)) {
            $err['creado_en'][] = 'Usar YYYY-MM-DDTHH:MM:SS.sssZ.';
        }
        $vinculos = $s->vinculos ?? [];
        if (! is_array($vinculos)) {
            $err['vinculos'][] = 'Lista [{tipo, id}].';
        } else {
            $claves = [];
            foreach ($vinculos as $i => $v) {
                if (! $v instanceof stdClass || ! isset(self::VINCULOS[$v->tipo ?? '']) || ! is_string($v->id ?? null) || ! preg_match(self::UUID, $v->id)) {
                    $err["vinculos.{$i}"][] = 'Vínculo {tipo, id} con tipo establecimiento|animal|lote|evento|potrero|operacion|contrato.';
                } else {
                    $claves[] = $v->tipo."\0".$v->id;
                }
            }
            $ordenadas = $claves;
            sort($ordenadas, SORT_STRING);
            if ($ordenadas !== $claves || count(array_unique($claves)) !== count($claves)) {
                $err['vinculos'][] = 'Ordenados por (tipo, id) y sin repetidos.';
            }
        }
        $archivos = $s->archivos ?? [];
        if (! is_array($archivos) || ($s->accion !== 'revocacion' && count($archivos) < 1) || count($archivos) > 50) {
            $err['archivos'][] = 'Entre 1 y 50 archivos (salvo revocación).';
        } else {
            foreach ($archivos as $i => $a) {
                if (! $a instanceof stdClass || ! is_string($a->sha256 ?? null) || ! preg_match('/^[0-9a-f]{64}$/', $a->sha256) || ! is_int($a->bytes ?? null) || $a->bytes < 1 || ! is_string($a->mime ?? null)) {
                    $err["archivos.{$i}"][] = '{sha256 hex64, bytes ≥ 1, mime}.';
                }
            }
        }
        if ($err !== []) {
            throw new EventoRechazado('esquema_invalido', 'El sobre no es válido.', $err);
        }
        if ($s->establecimiento_id !== $est->id) {
            throw new EventoRechazado('esquema_invalido', 'El sobre es de otro establecimiento.', ['establecimiento_id' => ['No coincide con el establecimiento.']]);
        }
        if ($s->autor_id !== $autor->id) {
            throw new EventoRechazado('sin_permiso', 'El autor del sobre no es el usuario autenticado.');
        }
        if ($canal === 'push' && ! (isset($s->dispositivo_id) && DB::table('dispositivos')->where('id', $s->dispositivo_id)->where('usuario_id', $autor->id)->whereNull('revocado_en')->exists())) {
            throw new EventoRechazado('sin_permiso', 'El dispositivo no pertenece al usuario.');
        }
    }
}
