<?php

namespace App\Domain\Hacienda\Eventos;

use App\Casts\PgTextArray;
use App\Domain\Catalogos\Parametros;
use App\Domain\Establecimientos\Interdiccion;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Hacienda\Proyecciones\Identificaciones;
use App\Domain\Hacienda\Proyecciones\Proyector;
use App\Domain\Integridad\Actions\RegistrarEnCadena;
use App\Domain\Integridad\CanonicalizacionInvalida;
use App\Domain\Integridad\CanonicalJson;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use stdClass;

/**
 * **Única puerta de entrada de eventos** (api-v1 §5.1): la usan el push de la app (sobre armado y hasheado
 * en el teléfono) y la web (sobre armado por el servidor). Implementa la validación de sync-e-integridad.md §7.4
 * en orden, el versionado/conflictos de §7.5 y la inserción de §5.3 en **una transacción por ítem**:
 *
 *   lock de la cadena → validar → registro de integridad (eslabón) → evento + evento_animales →
 *   proyecciones afectadas → cambios (cursor de pull).
 *
 * Reglas de especie, sexo, categorías y parámetros salen de los catálogos (nada de `if especie`).
 */
final class RegistrarEvento
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const FECHAHORA = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

    private const FECHA = '/^\d{4}-\d{2}-\d{2}$/';

    /** Tipos con un único sujeto animal. */
    private const UN_ANIMAL = ['hacienda.identificacion', 'hacienda.actualizacion_ficha', 'reproduccion.parto'];

    /** Tipos que dan de baja (para la regla «evento posterior a baja» y el retiro vigente en ventas). */
    private const SALIDAS = ['hacienda.egreso', 'hacienda.faena'];

    public function __construct(
        private readonly RegistrarEnCadena $registrarEnCadena,
        private readonly Proyector $proyector,
    ) {}

    /**
     * @param  array{canal: 'web'|'push', huella?: string|null, confirmar_advertencias?: bool, motivo_revision?: string}  $op
     */
    public function handle(Establecimiento $est, User $autor, stdClass $sobre, array $op): ResultadoRegistro
    {
        // 1–2. Estructura mínima, canonicalización y huella (antes de tocar la base).
        try {
            $huella = CanonicalJson::huella($sobre);
        } catch (CanonicalizacionInvalida $e) {
            throw new EventoRechazado('canonicalizacion', $e->getMessage());
        }
        if (! empty($op['huella']) && ! hash_equals($huella, (string) $op['huella'])) {
            throw new EventoRechazado('huella_invalida', 'La huella recalculada no coincide.');
        }
        $this->estructura($sobre, $est, $autor, $op['canal']);

        // 3. Idempotencia (también se repite bajo el lock).
        if ($duplicado = $this->duplicado($sobre->id, $huella)) {
            return $duplicado;
        }

        return DB::transaction(function () use ($est, $autor, $sobre, $op, $huella) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            if ($duplicado = $this->duplicado($sobre->id, $huella)) {
                return $duplicado;
            }

            $tipo = TiposEvento::get($sobre->tipo);
            if ($tipo === null || ! $tipo->activo) {
                throw new EventoRechazado('tipo_desconocido', "El tipo {$sobre->tipo} no existe en el servidor.");
            }
            if ((int) $sobre->tipo_version > $tipo->version_vigente) {
                throw new EventoRechazado('tipo_desconocido', "La versión {$sobre->tipo_version} de {$sobre->tipo} todavía no existe en el servidor.");
            }

            // 5. Permisos por rol y módulo.
            $correccion = (int) $sobre->version > 1;
            $ability = TiposEvento::ability($tipo->modulo, $correccion);
            if (! Gate::forUser($autor)->allows($ability, $est)) {
                throw new EventoRechazado('sin_permiso', 'Tu rol no permite registrar este tipo de evento.');
            }

            // 9 (parte 1). Versión base de una corrección/anulación.
            $base = $correccion ? $this->base($sobre, $est) : null;

            // 6. JSON Schema del payload (las anulaciones no llevan payload).
            if ($sobre->accion !== 'anulacion') {
                $errores = ValidadorPayload::validar($sobre->tipo, (int) $sobre->tipo_version, $sobre->payload);
                if ($errores !== []) {
                    throw new EventoRechazado('esquema_invalido', 'El payload no cumple el esquema del tipo.', $errores);
                }
            }

            // 7–8. Sujetos, referencias y reglas bloqueantes.
            $ctx = new ContextoEvento($est, $sobre, $tipo, $base);
            $this->sujetos($ctx);
            $this->referenciasYReglas($ctx, $op['canal']);

            // 9 (parte 2). Conflicto de versión: gana el servidor, la otra queda en revisión.
            $enRevision = false;
            $vigente = null;
            if ($base !== null) {
                $vigente = Evento::query()->where('evento_raiz_id', $base->evento_raiz_id)->where('en_revision', false)
                    ->orderByDesc('version')->first();
                if ($vigente->id !== $base->id) {
                    if ($op['canal'] === 'web') {
                        throw new EventoRechazado('version_no_vigente', 'Ese evento ya fue corregido: corregí la versión vigente.');
                    }
                    $enRevision = true;
                }
            }

            // 10. Coherencia: advierte, no rechaza (en la web se confirma antes de guardar).
            $obs = $this->observaciones($ctx, $op['canal'], $autor);
            $advertencias = array_values(array_filter($obs, fn ($o) => ! $o['interna']));
            if ($op['canal'] === 'web' && $advertencias !== [] && empty($op['confirmar_advertencias'])) {
                throw new ApiException(422, 'requiere_confirmacion', __('Hay advertencias: confirmá para registrar igual.'), [], array_map(
                    fn ($o) => ['codigo' => $o['codigo'], 'mensaje' => $o['mensaje'], 'detalle' => (object) ($o['detalle'] ?? [])], $advertencias,
                ));
            }
            $codigos = array_values(array_unique(array_column($obs, 'codigo')));

            // Inserción: eslabón → evento → animales nuevos → evento_animales.
            $registro = $this->registrarEnCadena->handle($cadena, 'evento', $sobre->id, $huella);
            $evento = $this->insertar($ctx, $autor, $huella, $registro, $enRevision, $codigos);
            $this->crearAnimales($ctx, $evento);
            $animalIds = $ctx->animalIdsDelEvento();
            foreach ($animalIds as $aid) {
                DB::table('evento_animales')->insert([
                    'evento_id' => $evento->id, 'animal_id' => $aid, 'establecimiento_id' => $est->id, 'ocurrido_en' => $evento->ocurrido_en,
                ]);
            }

            // Revisiones (conflicto de versión y coherencia que viene de la concurrencia offline).
            $revisionId = null;
            if ($enRevision) {
                $revisionId = $this->abrirRevision($est->id, 'correccion_en_conflicto', 'evento', $evento->id, [
                    'evento_raiz_id' => $base->evento_raiz_id, 'vigente_id' => $vigente->id, 'vigente_version' => $vigente->version,
                    'en_revision_id' => $evento->id, 'corrige_evento_id' => $base->id,
                ], $cadena);
            }
            foreach ($obs as $o) {
                if ($o['revision'] ?? false) {
                    $this->abrirRevision($est->id, $o['codigo'], 'evento', $evento->id, $o['detalle'] ?? [], $cadena);
                }
            }

            // Proyecciones afectadas + cambios para el pull.
            if (! $enRevision) {
                $this->proyector->aplicarEvento($evento, $ctx->animalesAfectados(), $ctx->lotesAfectados(), $cadena);
            }
            app(RegistrarCambio::class)->handle($est->id, 'evento', $evento->id, cadena: $cadena);

            return new ResultadoRegistro(
                $enRevision ? 'en_revision' : 'aceptado', $evento, $registro->seq, $registro->eslabon, $codigos, $revisionId,
                $vigente && $enRevision ? ['id' => $vigente->id, 'version' => $vigente->version, 'autor' => $vigente->autor?->nombre] : null,
            );
        });
    }

    private function duplicado(string $id, string $huella): ?ResultadoRegistro
    {
        $existente = Evento::query()->find($id);
        if ($existente === null) {
            return null;
        }
        if (! hash_equals($existente->hash, $huella)) {
            throw new EventoRechazado('id_reutilizado', 'Ese id ya existe con otro contenido.');
        }
        $r = DB::table('registros_integridad')->where('id', $existente->registro_integridad_id)->first();

        return new ResultadoRegistro('duplicado', $existente, (int) $r->seq, $r->eslabon, PgTextArray::parse($existente->observaciones_validacion));
    }

    /** §3.1: campos obligatorios, formatos y pertenencia (establecimiento, autor, dispositivo). */
    private function estructura(stdClass $s, Establecimiento $est, User $autor, string $canal): void
    {
        $err = [];
        $req = ['esquema', 'id', 'establecimiento_id', 'evento_raiz_id', 'version', 'accion', 'tipo', 'tipo_version', 'sujetos', 'ocurrido_en', 'creado_en', 'autor_id', 'origen'];
        foreach ($req as $campo) {
            if (! isset($s->{$campo})) {
                $err[$campo][] = "Falta {$campo}.";
            }
        }
        if ($err !== []) {
            throw new EventoRechazado('esquema_invalido', 'El sobre está incompleto.', $err);
        }
        if ($s->esquema !== 'tropilla.evento/1') {
            $err['esquema'][] = 'Esquema de sobre desconocido.';
        }
        foreach (['id', 'establecimiento_id', 'evento_raiz_id', 'autor_id'] as $campo) {
            if (! is_string($s->{$campo}) || ! preg_match(self::UUID, $s->{$campo})) {
                $err[$campo][] = 'UUID inválido.';
            }
        }
        if (! is_int($s->version) || $s->version < 1 || ! is_int($s->tipo_version) || $s->tipo_version < 1) {
            $err['version'][] = 'version y tipo_version son enteros ≥ 1.';
        }
        if (! in_array($s->accion, ['alta', 'correccion', 'anulacion'], true)) {
            $err['accion'][] = 'Acción inválida.';
        }
        if (! in_array($s->origen, ['app', 'web', 'importacion', 'sistema'], true)) {
            $err['origen'][] = 'Origen inválido.';
        }
        if (! is_string($s->ocurrido_en) || ! (preg_match(self::FECHA, $s->ocurrido_en) || preg_match(self::FECHAHORA, $s->ocurrido_en))) {
            $err['ocurrido_en'][] = 'Usar YYYY-MM-DD o YYYY-MM-DDTHH:MM:SS.sssZ.';
        }
        if (! is_string($s->creado_en) || ! preg_match(self::FECHAHORA, $s->creado_en)) {
            $err['creado_en'][] = 'Usar YYYY-MM-DDTHH:MM:SS.sssZ.';
        }
        if (! $s->sujetos instanceof stdClass) {
            $err['sujetos'][] = 'sujetos es un objeto.';
        }
        if ($s->accion !== 'anulacion' && ! (isset($s->payload) && $s->payload instanceof stdClass)) {
            $err['payload'][] = 'payload es un objeto ({} permitido).';
        }
        if ($s->accion === 'alta' && ($s->version !== 1 || $s->id !== $s->evento_raiz_id || isset($s->corrige_evento_id) || isset($s->hash_anterior))) {
            $err['version'][] = 'Un alta es versión 1 con evento_raiz_id = id.';
        }
        if ($s->accion !== 'alta' && ($s->version < 2 || ! isset($s->corrige_evento_id, $s->hash_anterior) || trim((string) ($s->motivo ?? '')) === '')) {
            $err['motivo'][] = 'Correcciones y anulaciones llevan version > 1, corrige_evento_id, hash_anterior y motivo.';
        }
        if (isset($s->motivo) && mb_strlen((string) $s->motivo) > 500) {
            $err['motivo'][] = 'Máximo 500 caracteres.';
        }
        foreach (['responsable' => 200, 'observaciones' => 2000] as $campo => $max) {
            if (isset($s->{$campo}) && (! is_string($s->{$campo}) || mb_strlen($s->{$campo}) > $max)) {
                $err[$campo][] = "Texto de hasta {$max} caracteres.";
            }
        }
        if (isset($s->adjuntos) && (! is_array($s->adjuntos) || array_filter($s->adjuntos, fn ($h) => ! is_string($h) || ! preg_match('/^[0-9a-f]{64}$/', $h)))) {
            $err['adjuntos'][] = 'Lista de SHA-256 en hex minúscula.';
        }
        if (isset($s->ubicacion_carga) && ! ($s->ubicacion_carga instanceof stdClass && is_string($s->ubicacion_carga->lat ?? null) && is_string($s->ubicacion_carga->lon ?? null))) {
            $err['ubicacion_carga'][] = '{lat, lon} como decimales string.';
        }
        if ($err !== []) {
            throw new EventoRechazado('esquema_invalido', 'El sobre no es válido.', $err);
        }

        // 4. Pertenencia.
        if ($s->establecimiento_id !== $est->id) {
            throw new EventoRechazado('esquema_invalido', 'El sobre es de otro establecimiento.', ['establecimiento_id' => ['No coincide con el establecimiento.']]);
        }
        if ($s->autor_id !== $autor->id) {
            throw new EventoRechazado('sin_permiso', 'El autor del sobre no es el usuario autenticado.');
        }
        if ($canal === 'push') {
            $ok = isset($s->dispositivo_id) && DB::table('dispositivos')->where('id', $s->dispositivo_id)->where('usuario_id', $autor->id)->whereNull('revocado_en')->exists();
            if (! $ok) {
                throw new EventoRechazado('sin_permiso', 'El dispositivo no pertenece al usuario.');
            }
        }
    }

    /** Versión que se corrige/anula: misma raíz, mismo tipo, huella encadenada. */
    private function base(stdClass $s, Establecimiento $est): Evento
    {
        /** @var Evento|null $base */
        $base = Evento::query()->where('id', $s->corrige_evento_id)->where('establecimiento_id', $est->id)->first();
        if ($base === null) {
            throw new EventoRechazado('dependencia_faltante', 'La versión que se corrige todavía no está en el servidor.');
        }
        $err = [];
        if ($base->evento_raiz_id !== $s->evento_raiz_id) {
            $err['evento_raiz_id'][] = 'No coincide con la raíz de la versión corregida.';
        }
        if ($base->hash !== $s->hash_anterior) {
            $err['hash_anterior'][] = 'No es la huella de la versión corregida.';
        }
        if ((int) $s->version !== $base->version + 1) {
            $err['version'][] = 'Tiene que ser la versión corregida + 1.';
        }
        if ($base->tipo !== $s->tipo) {
            $err['tipo'][] = 'Una corrección no cambia el tipo de evento.';
        }
        if ($base->accion === 'anulacion') {
            $err['corrige_evento_id'][] = 'Un evento anulado no se corrige.';
        }
        if ($err !== []) {
            throw new EventoRechazado('regla_bloqueante', 'La corrección no encadena con la versión corregida.', $err);
        }

        return $base;
    }

    /** §3.1.1: forma de los sujetos y tipos admitidos por el evento. */
    private function sujetos(ContextoEvento $ctx): void
    {
        $s = $ctx->sobre->sujetos;
        $tipoSujeto = $s->tipo ?? null;
        if (! in_array($tipoSujeto, $ctx->tipo->sujetos, true)) {
            throw new EventoRechazado('regla_bloqueante', "Este evento no admite sujetos de tipo «{$tipoSujeto}».", ['sujetos.tipo' => ['Sujeto no admitido.']]);
        }
        $err = [];
        $ids = $s->animal_ids ?? null;
        if ($ids !== null) {
            if (! is_array($ids) || count($ids) < 1 || count($ids) > 1000) {
                $err['sujetos.animal_ids'][] = 'Entre 1 y 1000 ids.';
            } else {
                $ordenados = $ids;
                sort($ordenados, SORT_STRING);
                if ($ordenados !== $ids || count(array_unique($ids)) !== count($ids)) {
                    $err['sujetos.animal_ids'][] = 'Ordenados ascendente y sin repetidos.';
                }
                foreach ($ids as $id) {
                    if (! is_string($id) || ! preg_match(self::UUID, $id)) {
                        $err['sujetos.animal_ids'][] = 'UUID inválido.';
                        break;
                    }
                }
            }
        }
        match ($tipoSujeto) {
            'animales' => $ids === null ? $err['sujetos.animal_ids'][] = 'Obligatorio.' : null,
            'lote' => empty($s->lote_id) ? $err['sujetos.lote_id'][] = 'Obligatorio.' : null,
            'potrero' => empty($s->potrero_id) ? $err['sujetos.potrero_id'][] = 'Obligatorio.' : null,
            default => null,
        };
        if (isset($s->conteo)) {
            if (! $s->conteo instanceof stdClass || (array) $s->conteo === []) {
                $err['sujetos.conteo'][] = 'Objeto {categoria: cabezas}.';
            } else {
                foreach ((array) $s->conteo as $cat => $n) {
                    if (! is_int($n) || $n < 1) {
                        $err["sujetos.conteo.{$cat}"][] = 'Entero ≥ 1.';
                    }
                }
            }
            if ($tipoSujeto !== 'lote') {
                $err['sujetos.conteo'][] = 'El conteo es para lotes anónimos.';
            }
        }
        foreach (['lote_id', 'potrero_id'] as $c) {
            if (isset($s->{$c}) && (! is_string($s->{$c}) || ! preg_match(self::UUID, $s->{$c}))) {
                $err["sujetos.{$c}"][] = 'UUID inválido.';
            }
        }
        if (in_array($ctx->sobre->tipo, self::UN_ANIMAL, true) && is_array($ids) && count($ids) !== 1) {
            $err['sujetos.animal_ids'][] = 'Este evento es de un solo animal.';
        }
        if ($err !== []) {
            throw new EventoRechazado('esquema_invalido', 'Los sujetos no son válidos.', $err);
        }
    }

    /** §7.4 pasos 7–8: referencias en el establecimiento y reglas determinísticas (datos de catálogo). */
    private function referenciasYReglas(ContextoEvento $ctx, string $canal): void
    {
        $est = $ctx->est;
        $s = $ctx->sobre;
        $p = $s->payload ?? new stdClass;
        $esAnulacion = $s->accion === 'anulacion';

        // Lote / potrero de los sujetos.
        if (! empty($s->sujetos->lote_id)) {
            $ctx->lote = DB::table('lotes')->where('id', $s->sujetos->lote_id)->where('establecimiento_id', $est->id)->first();
            if ($ctx->lote === null) {
                throw new EventoRechazado('dependencia_faltante', 'El lote todavía no está en el servidor.');
            }
            foreach (array_keys((array) ($s->sujetos->conteo ?? [])) as $cat) {
                $this->categoria($cat, $ctx->lote->especie_codigo, null, "sujetos.conteo.{$cat}");
            }
        }
        if (! empty($s->sujetos->potrero_id) && ! DB::table('potreros')->where('id', $s->sujetos->potrero_id)->where('establecimiento_id', $est->id)->exists()) {
            throw new EventoRechazado('dependencia_faltante', 'El potrero todavía no está en el servidor.');
        }

        // Animales que crea el evento (alta, nacimiento, ingreso, parto, individualización).
        $ctx->nuevos = $esAnulacion ? [] : $this->animalesNuevos($s->tipo, $p);
        if ($ctx->tipo->crea_animales && ! $esAnulacion) {
            $this->validarNuevos($ctx);
        }
        if ($ctx->base !== null && $ctx->tipo->crea_animales) {
            $creadosBase = DB::table('animales')->where('evento_origen_raiz_id', $ctx->base->evento_raiz_id)->pluck('especie_codigo', 'id')->all();
            if (! $esAnulacion) {
                $idsNuevos = array_column($ctx->nuevos, 'animal_id');
                sort($idsNuevos);
                $idsBase = array_keys($creadosBase);
                sort($idsBase);
                if ($idsNuevos !== $idsBase) {
                    throw new EventoRechazado('regla_bloqueante', 'Una corrección no cambia qué animales creó el evento.');
                }
                foreach ($ctx->nuevos as $n) {
                    if ($creadosBase[$n->animal_id] !== $n->especie) {
                        throw new EventoRechazado('regla_bloqueante', 'La especie de un animal no se corrige.', ['payload' => ['Especie inmutable.']]);
                    }
                }
            }
            $ctx->creadosPorBase = array_keys($creadosBase);
        }

        // Animales sujeto existentes (los que no crea este evento).
        $idsNuevos = array_column($ctx->nuevos, 'animal_id');
        $existentes = array_values(array_diff((array) ($s->sujetos->animal_ids ?? []), $idsNuevos));
        if ($ctx->base !== null && $ctx->tipo->crea_animales) {
            $existentes = array_values(array_diff($existentes, $ctx->creadosPorBase));
        }
        if ($existentes !== []) {
            $filas = DB::table('animales as a')->join('animales_estado as e', 'e.animal_id', '=', 'a.id')
                ->where('a.establecimiento_id', $est->id)->whereIn('a.id', $existentes)
                ->get(['a.id', 'a.especie_codigo', 'e.sexo', 'e.estado', 'e.baja_en', 'e.peso_kg', 'e.peso_fecha', 'e.retiro_carne_hasta', 'e.lote_id', 'e.raza_codigo'])
                ->keyBy('id');
            if ($filas->count() !== count($existentes)) {
                throw new EventoRechazado('dependencia_faltante', 'Hay animales que el servidor todavía no tiene.', [
                    'sujetos.animal_ids' => array_values(array_diff($existentes, $filas->keys()->all())),
                ]);
            }
            $ctx->sujetosExistentes = $filas->all();
            foreach ($filas as $a) {
                if ($a->estado === 'anulado') {
                    throw new EventoRechazado('regla_bloqueante', 'El animal fue anulado.', ['sujetos.animal_ids' => [$a->id]]);
                }
                if ($ctx->tipo->requiere_sexo && $a->sexo !== $ctx->tipo->requiere_sexo && ! $esAnulacion) {
                    throw new EventoRechazado('regla_bloqueante', __('Este evento no corresponde al sexo del animal.'), ['sujetos.animal_ids' => [$a->id]]);
                }
                if ($ctx->lote !== null && $ctx->lote->especie_codigo !== $a->especie_codigo) {
                    throw new EventoRechazado('regla_bloqueante', 'El lote es de otra especie.', ['sujetos.lote_id' => [$a->id]]);
                }
            }
        }

        if ($esAnulacion) {
            return;
        }

        // Referencias del payload.
        if (isset($p->potrero_destino_id) && ! DB::table('potreros')->where('id', $p->potrero_destino_id)->where('establecimiento_id', $est->id)->exists()) {
            throw new EventoRechazado('dependencia_faltante', 'El potrero destino todavía no está en el servidor.');
        }
        if (isset($p->lote_destino_id)) {
            $destino = DB::table('lotes')->where('id', $p->lote_destino_id)->where('establecimiento_id', $est->id)->first();
            if ($destino === null) {
                throw new EventoRechazado('dependencia_faltante', 'El lote destino todavía no está en el servidor.');
            }
            foreach ($ctx->sujetosExistentes as $a) {
                if ($a->especie_codigo !== $destino->especie_codigo) {
                    throw new EventoRechazado('regla_bloqueante', 'El lote destino es de otra especie.', ['payload.lote_destino_id' => [$a->id]]);
                }
            }
        }
        if ($s->tipo === 'hacienda.pesaje' && ($p->modo ?? null) === 'individual' && count((array) ($s->sujetos->animal_ids ?? [])) !== 1) {
            throw new EventoRechazado('regla_bloqueante', 'Un pesaje individual es de un solo animal.', ['sujetos.animal_ids' => ['Un solo animal en modo individual.']]);
        }
        if ($s->tipo === 'hacienda.pesaje' && ($p->modo ?? null) === 'promedio' && ! isset($p->cantidad)) {
            throw new EventoRechazado('esquema_invalido', 'El pesaje promedio lleva cantidad.', ['payload.cantidad' => ['Obligatorio en modo promedio.']]);
        }
        foreach (['categoria_nueva'] as $c) {
            if (isset($p->{$c})) {
                foreach ($ctx->sujetosExistentes as $a) {
                    $this->categoria($p->{$c}, $a->especie_codigo, $a->sexo, "payload.{$c}");
                }
                if ($ctx->lote !== null && $ctx->sujetosExistentes === []) {
                    $this->categoria($p->{$c}, $ctx->lote->especie_codigo, null, "payload.{$c}");
                }
            }
        }
        foreach ((array) ($p->movimientos ?? []) as $i => $m) {
            if ($ctx->lote === null) {
                throw new EventoRechazado('regla_bloqueante', 'Los movimientos entre categorías son de lotes anónimos.');
            }
            $this->categoria($m->de, $ctx->lote->especie_codigo, null, "payload.movimientos.{$i}.de");
            $this->categoria($m->a, $ctx->lote->especie_codigo, null, "payload.movimientos.{$i}.a");
        }
        if ($s->tipo === 'hacienda.muerte' && ! DB::table('catalogo_items')->where('lista', 'causas_muerte')->where('codigo', $p->causa)
            ->where(fn ($q) => $q->whereNull('establecimiento_id')->orWhere('establecimiento_id', $est->id))->exists()) {
            throw new EventoRechazado('esquema_invalido', 'Causa de muerte desconocida.', ['payload.causa' => ['No está en el catálogo.']]);
        }
        if (isset($p->identificacion) && $p->identificacion->tipo === 'rfid' && ! preg_match('/^\d{15}$/', $p->identificacion->valor)) {
            throw new EventoRechazado('regla_bloqueante', 'El RFID tiene 15 dígitos.', ['payload.identificacion.valor' => ['15 dígitos.']]);
        }
        if (isset($p->cambios) && $p->cambios instanceof stdClass && isset($p->cambios->raza)) {
            foreach ($ctx->sujetosExistentes as $a) {
                $this->raza($p->cambios->raza, $a->especie_codigo, 'payload.cambios.raza', $est->id);
            }
        }
        // Evento posterior a una baja: en la web (online) bloquea; por sync se acepta con observación (concurrencia offline).
        if ($canal === 'web' && $s->accion === 'alta') {
            $fecha = $ctx->ocurridoEn()->toDateString();
            foreach ($ctx->sujetosExistentes as $a) {
                if ($a->estado !== 'activo' && $a->baja_en !== null && $a->baja_en < $fecha) {
                    throw new EventoRechazado('regla_bloqueante', __('El animal ya no está en el establecimiento (baja del :f).', ['f' => $a->baja_en]), ['sujetos.animal_ids' => [$a->id]]);
                }
            }
        }
    }

    /** @return list<stdClass> animal_nuevo[] del payload (con `_madre` para crías de parto). */
    private function animalesNuevos(string $tipo, stdClass $p): array
    {
        $nuevos = [];
        foreach ((array) ($p->animales ?? []) as $a) {
            $nuevos[] = $a;
        }
        foreach ((array) ($p->crias ?? []) as $c) {
            if (($c->viva ?? false) && isset($c->animal)) {
                $nuevos[] = $c->animal;
            }
        }

        return $nuevos;
    }

    private function validarNuevos(ContextoEvento $ctx): void
    {
        $s = $ctx->sobre;
        $p = $s->payload;
        $est = $ctx->est;
        $ids = array_column($ctx->nuevos, 'animal_id');

        if (count(array_unique($ids)) !== count($ids)) {
            throw new EventoRechazado('regla_bloqueante', 'Hay animal_id repetidos.');
        }
        foreach ((array) ($p->crias ?? []) as $i => $c) {
            if (($c->viva ?? false) && ! isset($c->animal)) {
                throw new EventoRechazado('esquema_invalido', 'Una cría viva se da de alta.', ["payload.crias.{$i}.animal" => ['Obligatorio si viva.']]);
            }
        }
        $sujetoTipo = $s->sujetos->tipo;
        if ($sujetoTipo === 'animales' && $s->tipo !== 'reproduccion.parto') {
            $ordenados = $ids;
            sort($ordenados);
            if ($ids === [] || $ordenados !== (array) $s->sujetos->animal_ids) {
                throw new EventoRechazado('regla_bloqueante', 'sujetos.animal_ids tienen que ser los animales que se dan de alta.', ['sujetos.animal_ids' => ['No coinciden con payload.animales.']]);
            }
        }
        if ($s->tipo === 'hacienda.individualizacion') {
            $porCategoria = [];
            foreach ($ctx->nuevos as $n) {
                $porCategoria[$n->categoria] = ($porCategoria[$n->categoria] ?? 0) + 1;
            }
            ksort($porCategoria);
            $conteo = (array) ($s->sujetos->conteo ?? []);
            ksort($conteo);
            if ($porCategoria !== $conteo) {
                throw new EventoRechazado('regla_bloqueante', 'Se individualiza un animal por cada cabeza del conteo.', ['payload.animales' => ['No coincide con sujetos.conteo.']]);
            }
        }
        // En correcciones los animales ya existen (se validó que el conjunto no cambie).
        if ($ctx->base === null && $ids !== [] && DB::table('animales')->whereIn('id', $ids)->exists()) {
            throw new EventoRechazado('regla_bloqueante', 'Un animal_id ya existe.', ['payload.animales' => DB::table('animales')->whereIn('id', $ids)->pluck('id')->all()]);
        }
        $madre = $s->tipo === 'reproduccion.parto' ? ($s->sujetos->animal_ids[0] ?? null) : null;
        $especieMadre = $madre ? DB::table('animales')->where('id', $madre)->value('especie_codigo') : null;

        foreach ($ctx->nuevos as $i => $n) {
            $campo = "payload.animales.{$i}";
            if (! in_array($n->especie, $est->especies, true)) {
                throw new EventoRechazado('regla_bloqueante', "El establecimiento no tiene declarada la especie {$n->especie}.", ["{$campo}.especie" => ['Agregá la especie en los datos del establecimiento.']]);
            }
            if ($especieMadre !== null && $n->especie !== $especieMadre) {
                throw new EventoRechazado('regla_bloqueante', 'La cría es de otra especie que la madre.', ["{$campo}.especie" => ['Especie distinta de la madre.']]);
            }
            $this->categoria($n->categoria, $n->especie, $n->sexo, "{$campo}.categoria");
            if (isset($n->raza)) {
                $this->raza($n->raza, $n->especie, "{$campo}.raza", $est->id);
            }
            foreach ((array) ($n->cruza ?? []) as $j => $c) {
                $this->raza($c->raza, $n->especie, "{$campo}.cruza.{$j}.raza", $est->id);
            }
            $loteId = $n->lote_id ?? ($s->sujetos->lote_id ?? null);
            if ($loteId !== null) {
                $lote = DB::table('lotes')->where('id', $loteId)->where('establecimiento_id', $est->id)->first();
                if ($lote === null) {
                    throw new EventoRechazado('dependencia_faltante', 'El lote todavía no está en el servidor.');
                }
                if ($lote->especie_codigo !== $n->especie) {
                    throw new EventoRechazado('regla_bloqueante', 'Un lote no mezcla especies.', ["{$campo}.lote_id" => ['El lote es de otra especie.']]);
                }
            }
            if (isset($n->potrero_id) && ! DB::table('potreros')->where('id', $n->potrero_id)->where('establecimiento_id', $est->id)->exists()) {
                throw new EventoRechazado('dependencia_faltante', 'El potrero todavía no está en el servidor.');
            }
            foreach (['madre_id', 'padre_id'] as $c) {
                if (isset($n->{$c}) && ! in_array($n->{$c}, $ids, true) && ! DB::table('animales')->where('id', $n->{$c})->where('establecimiento_id', $est->id)->exists()) {
                    throw new EventoRechazado('dependencia_faltante', 'La madre o el padre todavía no están en el servidor.');
                }
            }
            foreach ((array) ($n->identificaciones ?? []) as $j => $ident) {
                if ($ident->tipo === 'rfid' && ! preg_match('/^\d{15}$/', $ident->valor)) {
                    throw new EventoRechazado('regla_bloqueante', 'El RFID tiene 15 dígitos.', ["{$campo}.identificaciones.{$j}.valor" => ['15 dígitos.']]);
                }
            }
        }
    }

    private function categoria(string $codigo, string $especie, ?string $sexo, string $campo): void
    {
        $c = DB::table('categorias')->where('codigo', $codigo)->where('activa', true)->first();
        if ($c === null || $c->especie_codigo !== $especie) {
            throw new EventoRechazado('regla_bloqueante', "La categoría {$codigo} no es de la especie {$especie}.", [$campo => ['Categoría de otra especie o inexistente.']]);
        }
        if ($sexo !== null && $c->sexo !== null && $c->sexo !== $sexo) {
            throw new EventoRechazado('regla_bloqueante', "La categoría {$codigo} no corresponde al sexo.", [$campo => ['Categoría de otro sexo.']]);
        }
    }

    private function raza(string $codigo, string $especie, string $campo, string $establecimientoId): void
    {
        $ok = DB::table('razas')->where('codigo', $codigo)->where('especie_codigo', $especie)
            ->where(fn ($q) => $q->whereNull('establecimiento_id')->orWhere('establecimiento_id', $establecimientoId))
            ->exists();
        if (! $ok) {
            throw new EventoRechazado('regla_bloqueante', "La raza {$codigo} no es de la especie {$especie}.", [$campo => ['Raza de otra especie o inexistente.']]);
        }
    }

    /**
     * §7.4 paso 10: coherencia. `interna` = solo para el registro (no se pregunta en la web).
     *
     * @return list<array{codigo: string, mensaje: string, interna: bool, revision?: bool, detalle?: array<string, mixed>}>
     */
    private function observaciones(ContextoEvento $ctx, string $canal, User $autor): array
    {
        $obs = [];
        $s = $ctx->sobre;
        if ($s->accion === 'anulacion') {
            return [];
        }
        $p = $s->payload;
        $fecha = $ctx->ocurridoEn()->toDateString();

        if ($canal === 'push' && Carbon::parse($s->creado_en)->gt(now()->addDay())) {
            $obs[] = ['codigo' => 'reloj_dispositivo', 'mensaje' => 'La hora del teléfono está adelantada.', 'interna' => true];
        }
        if ($canal === 'push' && $s->accion === 'alta') {
            foreach ($ctx->sujetosExistentes as $a) {
                if ($a->estado !== 'activo' && $a->baja_en !== null && $a->baja_en < $fecha) {
                    $obs[] = ['codigo' => 'evento_posterior_a_baja', 'mensaje' => 'El animal ya estaba dado de baja.', 'interna' => true, 'revision' => true,
                        'detalle' => ['animal_id' => $a->id, 'baja_en' => $a->baja_en]];
                }
            }
        }

        // Identificaciones duplicadas (nuevas o colocadas).
        $idents = [];
        foreach ($ctx->nuevos as $n) {
            foreach ((array) ($n->identificaciones ?? []) as $i) {
                $idents[] = [$n->animal_id, $i, $n];
            }
        }
        if ($s->tipo === 'hacienda.identificacion' && in_array($p->accion, ['colocacion', 'reposicion'], true)) {
            $idents[] = [$s->sujetos->animal_ids[0], $p->identificacion, null];
        }
        foreach ($idents as [$animalId, $i, $nuevo]) {
            $otro = DB::table('animal_identificaciones')->where('establecimiento_id', $ctx->est->id)->where('tipo', $i->tipo)
                ->where('valor_normalizado', Identificaciones::normalizar($i->valor))->where('vigente', true)
                ->where('animal_id', '<>', $animalId)->value('animal_id');
            if ($otro !== null) {
                if ($canal === 'web') {
                    throw new EventoRechazado('identificacion_duplicada', __('La identificación :v ya está vigente en otro animal.', ['v' => $i->valor]), ['identificacion' => [$i->valor]]);
                }
                $obs[] = ['codigo' => 'identificacion_duplicada', 'mensaje' => "La identificación {$i->valor} ya está en otro animal.", 'interna' => true, 'revision' => true,
                    'detalle' => ['tipo' => $i->tipo, 'valor' => $i->valor, 'animal_id' => $animalId, 'otro_animal_id' => $otro]];
            }
            if ($i->tipo === 'tatuaje' && $nuevo !== null && isset($nuevo->raza)) {
                $prefijo = DB::table('razas')->where('codigo', $nuevo->raza)->value('prefijo_tatuaje');
                if ($prefijo && ! str_starts_with(strtoupper($i->valor), $prefijo)) {
                    $obs[] = ['codigo' => 'tatuaje_prefijo', 'mensaje' => "El tatuaje de esta raza empieza con {$prefijo}.", 'interna' => false, 'detalle' => ['prefijo' => $prefijo]];
                }
            }
        }

        // Pesaje atípico (> X % en < N días, parámetros por especie).
        if ($s->tipo === 'hacienda.pesaje' && $p->modo === 'individual') {
            foreach ($ctx->sujetosExistentes as $a) {
                if ($a->peso_kg !== null && $a->peso_fecha !== null) {
                    $dias = Carbon::parse($a->peso_fecha)->diffInDays(Carbon::parse($fecha), true);
                    $maxPct = (float) Parametros::valor('pesaje_atipico_pct', $a->especie_codigo, null, $ctx->est->id);
                    $maxDias = (int) Parametros::valor('pesaje_atipico_dias', $a->especie_codigo, null, $ctx->est->id);
                    $dif = abs((float) $p->peso_kg - (float) $a->peso_kg) / max((float) $a->peso_kg, 0.1) * 100;
                    if ($dias < $maxDias && $dif > $maxPct) {
                        $obs[] = ['codigo' => 'pesaje_atipico', 'mensaje' => sprintf('El peso difiere %.0f %% del anterior (%s kg hace %d días).', $dif, rtrim(rtrim($a->peso_kg, '0'), '.'), $dias), 'interna' => false,
                            'detalle' => ['peso_anterior_kg' => $a->peso_kg, 'dias' => $dias]];
                    }
                }
            }
        }

        // Venta o faena con retiro vigente; movimiento con interdicción.
        if (in_array($s->tipo, self::SALIDAS, true)) {
            foreach ($ctx->sujetosExistentes as $a) {
                if ($a->retiro_carne_hasta !== null && $a->retiro_carne_hasta >= $fecha) {
                    $obs[] = ['codigo' => 'retiro_vigente', 'mensaje' => "Un animal tiene retiro vigente hasta {$a->retiro_carne_hasta}.", 'interna' => false, 'detalle' => ['animal_id' => $a->id]];
                }
            }
        }
        if ($s->tipo === 'hacienda.egreso' && ($p->destino ?? null) !== 'faena' && Interdiccion::vigente($ctx->est->id) !== null) {
            $obs[] = ['codigo' => 'interdiccion_vigente', 'mensaje' => 'El establecimiento tiene una interdicción declarada.', 'interna' => false];
        }

        return $obs;
    }

    /**
     * @param  list<string>  $observaciones
     */
    /**
     * INSERT único (la tabla es append-only: no hay UPDATE posterior). `payload` y `sobre` se guardan desde
     * el stdClass para conservar `{}` vs `[]`.
     *
     * @param  list<string>  $observaciones
     */
    private function insertar(ContextoEvento $ctx, User $autor, string $huella, object $registro, bool $enRevision, array $observaciones): Evento
    {
        $s = $ctx->sobre;
        $json = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::table('eventos')->insert([
            'id' => $s->id,
            'establecimiento_id' => $ctx->est->id,
            'evento_raiz_id' => $s->evento_raiz_id,
            'version' => $s->version,
            'accion' => $s->accion,
            'corrige_evento_id' => $s->corrige_evento_id ?? null,
            'motivo' => $s->motivo ?? null,
            'en_revision' => $enRevision,
            'tipo' => $s->tipo,
            'tipo_version' => $s->tipo_version,
            'sujeto_tipo' => $s->sujetos->tipo,
            'lote_id' => $s->sujetos->lote_id ?? null,
            'potrero_id' => $s->sujetos->potrero_id ?? null,
            'conteo' => isset($s->sujetos->conteo) ? $json($s->sujetos->conteo) : null,
            'payload' => $json($s->payload ?? new stdClass),
            'ocurrido_en' => $ctx->ocurridoEn(),
            'ocurrido_precision' => strlen($s->ocurrido_en) === 10 ? 'dia' : 'minuto',
            'creado_en' => Carbon::parse($s->creado_en),
            'registrado_en' => $registro->registrado_en,
            'autor_id' => $autor->id,
            'dispositivo_id' => $s->dispositivo_id ?? null,
            'origen' => $s->origen,
            'responsable' => $s->responsable ?? null,
            'ubicacion_carga' => isset($s->ubicacion_carga)
                ? DB::raw(sprintf("ST_GeogFromText('SRID=4326;POINT(%F %F)')", (float) $s->ubicacion_carga->lon, (float) $s->ubicacion_carga->lat))
                : null,
            'adjuntos' => empty($s->adjuntos) ? null : PgTextArray::literal($s->adjuntos),
            'observaciones_validacion' => $observaciones === [] ? null : PgTextArray::literal($observaciones),
            'sobre' => $json($s),
            'hash' => $huella,
            'hash_anterior' => $s->hash_anterior ?? null,
            'registro_integridad_id' => $registro->id,
        ]);

        return Evento::query()->findOrFail($s->id);
    }

    private function crearAnimales(ContextoEvento $ctx, Evento $evento): void
    {
        if ($ctx->base !== null || $evento->en_revision) {
            return;
        }
        foreach ($ctx->nuevos as $n) {
            DB::table('animales')->insert([
                'id' => $n->animal_id,
                'establecimiento_id' => $ctx->est->id,
                'especie_codigo' => $n->especie,
                'evento_origen_raiz_id' => $evento->evento_raiz_id,
                'lote_origen_anonimo_id' => $ctx->sobre->tipo === 'hacienda.individualizacion' ? $ctx->sobre->sujetos->lote_id : null,
                'created_at' => now(),
            ]);
        }
    }

    /** @param array<string, mixed> $detalle */
    private function abrirRevision(string $establecimientoId, string $tipo, string $entidadTipo, string $entidadId, array $detalle, Cadena $cadena): string
    {
        $id = (string) Str::uuid7();
        DB::table('revisiones')->insert([
            'id' => $id, 'establecimiento_id' => $establecimientoId, 'tipo' => $tipo, 'entidad_tipo' => $entidadTipo,
            'entidad_id' => $entidadId, 'detalle' => json_encode((object) $detalle), 'estado' => 'abierta',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(RegistrarCambio::class)->handle($establecimientoId, 'revision', $id, cadena: $cadena);

        return $id;
    }
}
