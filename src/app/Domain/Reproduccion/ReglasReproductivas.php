<?php

namespace App\Domain\Reproduccion;

use App\Domain\Catalogos\Parametros;
use App\Domain\Hacienda\Eventos\ContextoEvento;
use App\Domain\Hacienda\Eventos\EventoRechazado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reglas del módulo Reproducción (functional/02 §2.2, modelo-datos §6.3) que `RegistrarEvento` aplica a los
 * eventos `reproduccion.*`: bloqueantes (§7.4 paso 8) y de coherencia (paso 10, advertencias). Los umbrales
 * salen de `parametros` por especie/raza/establecimiento; nada de `if especie`.
 */
final class ReglasReproductivas
{
    /** modelo-datos §3.3: el parto advierte si la gestación es menor que `gestacion_min_dias` − 30. */
    private const TOLERANCIA_GESTACION_DIAS = 30;

    /** Bloqueantes: se llaman después de validar sujetos y referencias genéricas (no para anulaciones). */
    public function validar(ContextoEvento $ctx): void
    {
        $p = $ctx->sobre->payload ?? new stdClass;
        match ($ctx->sobre->tipo) {
            'reproduccion.servicio_natural' => $this->servicioNatural($ctx, $p),
            'reproduccion.inseminacion' => $this->inseminacion($ctx, $p),
            'reproduccion.tacto' => $this->tacto($ctx, $p),
            'reproduccion.parto' => $this->parto($ctx, $p),
            'reproduccion.aborto' => $this->diasMaximos($ctx, $p),
            'reproduccion.destete' => $this->destete($ctx, $p),
            default => null,
        };
    }

    /**
     * Coherencia: advertencias que en la web piden confirmación y por sync quedan como observación.
     *
     * @return list<array{codigo: string, mensaje: string, interna: bool, detalle?: array<string, mixed>}>
     */
    public function observaciones(ContextoEvento $ctx): array
    {
        $obs = [];
        $tipo = $ctx->sobre->tipo;
        $p = $ctx->sobre->payload ?? new stdClass;

        if (in_array($tipo, ['reproduccion.servicio_natural', 'reproduccion.inseminacion'], true)) {
            foreach ($this->hembras($ctx) as $h) {
                if ($h->estado_reproductivo === 'prenada') {
                    $obs[] = ['codigo' => 'servicio_en_prenada', 'mensaje' => 'La hembra figura preñada: el servicio reemplaza la fecha probable de parto.',
                        'interna' => false, 'detalle' => ['animal_id' => $h->animal_id, 'fecha_probable_parto' => $h->fecha_probable_parto]];
                }
            }
            $toros = (array) ($p->toro_ids ?? []);
            if (isset($p->semen->toro_id)) {
                $toros[] = $p->semen->toro_id;
            }
            foreach (DB::table('animales_estado')->whereIn('animal_id', $toros)->where('estado', '<>', 'activo')->get(['animal_id', 'estado']) as $t) {
                $obs[] = ['codigo' => 'toro_no_activo', 'mensaje' => "El toro ya no está activo ({$t->estado}).", 'interna' => false, 'detalle' => ['animal_id' => $t->animal_id]];
            }

            $torosTipados = array_map(fn ($id) => ['tipo' => 'interno', 'id' => $id], $toros);
            if (isset($p->semen->reproductor_externo_id)) {
                $torosTipados[] = ['tipo' => 'externo', 'id' => $p->semen->reproductor_externo_id];
            }
            foreach ($this->hembras($ctx) as $hembra) {
                $ancestrosHembra = $this->ancestros('interno', $hembra->animal_id, $ctx->est->id);
                foreach ($torosTipados as $toro) {
                    $compartidos = array_intersect_key($ancestrosHembra, $this->ancestros($toro['tipo'], $toro['id'], $ctx->est->id));
                    if ($compartidos !== []) {
                        $obs[] = ['codigo' => 'consanguinidad_3_generaciones', 'mensaje' => 'El macho y la hembra comparten ancestros en las últimas tres generaciones.',
                            'interna' => false, 'no_bloqueante' => true, 'detalle' => ['animal_id' => $hembra->animal_id, 'toro_id' => $toro['id'], 'ancestros_compartidos' => array_keys($compartidos)]];
                        break;
                    }
                }
            }
        }

        if ($tipo === 'reproduccion.tacto' && $p->resultado === 'prenada' && isset($p->dias_gestacion)) {
            foreach ($this->hembras($ctx) as $h) {
                if ((int) $p->dias_gestacion > $this->gestacion('gestacion_media_dias', $ctx, $h)) {
                    $obs[] = ['codigo' => 'fpp_pasada', 'mensaje' => 'Con esos días de gestación la fecha probable de parto ya pasó.',
                        'interna' => false, 'detalle' => ['animal_id' => $h->animal_id]];
                }
            }
        }

        // TPA-29: parto/aborto contra la gestación mínima de la especie/raza (advierte si < mínimo − 30 d, modelo-datos §3.3).
        // En correcciones el estado proyectado ya incluye el evento corregido: no se compara.
        if (in_array($tipo, ['reproduccion.parto', 'reproduccion.aborto'], true) && $ctx->base === null) {
            $fecha = $ctx->ocurridoEn()->setTimezone($ctx->est->zona_horaria)->startOfDay();
            foreach ($this->hembras($ctx) as $h) {
                $minimo = $this->gestacion('gestacion_min_dias', $ctx, $h) - self::TOLERANCIA_GESTACION_DIAS;
                if ($tipo === 'reproduccion.aborto') {
                    if (! in_array($h->estado_reproductivo, ['prenada', 'servida'], true)) {
                        $obs[] = ['codigo' => 'aborto_sin_prenez', 'mensaje' => 'La hembra no figura preñada ni servida.', 'interna' => false, 'detalle' => ['animal_id' => $h->animal_id]];
                    }

                    continue;
                }
                $concepcion = self::concepcion($h);
                if ($concepcion !== null && $concepcion->diffInDays($fecha, false) < $minimo) {
                    $obs[] = ['codigo' => 'gestacion_corta', 'mensaje' => "Desde el servicio/tacto pasaron menos de {$minimo} días: revisá la fecha del parto.",
                        'interna' => false, 'detalle' => ['animal_id' => $h->animal_id, 'dias' => (int) $concepcion->diffInDays($fecha, false), 'minimo' => $minimo]];
                }
                if ($h->ultimo_parto_fecha !== null && Carbon::parse($h->ultimo_parto_fecha)->diffInDays($fecha, false) < $minimo) {
                    $obs[] = ['codigo' => 'parto_reciente', 'mensaje' => "La hembra parió el {$h->ultimo_parto_fecha}: no puede volver a parir antes de {$minimo} días.",
                        'interna' => false, 'detalle' => ['animal_id' => $h->animal_id, 'ultimo_parto_fecha' => $h->ultimo_parto_fecha]];
                }
            }
        }
        if ($tipo === 'reproduccion.destete' && $ctx->base === null) {
            foreach (DB::table('animales_estado')->whereIn('animal_id', array_keys($ctx->sujetosExistentes))->whereNotNull('destete_fecha')->orderBy('animal_id')->get(['animal_id', 'destete_fecha']) as $a) {
                $obs[] = ['codigo' => 'ya_destetado', 'mensaje' => "El animal ya fue destetado el {$a->destete_fecha}.", 'interna' => false, 'detalle' => ['animal_id' => $a->animal_id]];
            }
        }

        return $obs;
    }

    /** @return array<string, true> */
    private function ancestros(string $tipo, string $id, string $establecimientoId): array
    {
        $vistos = [];
        $frontera = [['tipo' => $tipo, 'id' => $id]];
        for ($generacion = 1; $generacion <= 3 && $frontera !== []; $generacion++) {
            $siguiente = [];
            foreach ($frontera as $nodo) {
                $tabla = $nodo['tipo'] === 'interno' ? 'animales_estado' : 'reproductores_externos';
                $claveId = $nodo['tipo'] === 'interno' ? 'animal_id' : 'id';
                $fila = DB::table($tabla)->where($claveId, $nodo['id'])->where('establecimiento_id', $establecimientoId)->first();
                if ($fila === null) {
                    continue;
                }
                foreach ([['madre_id', 'interno'], ['padre_id', 'interno'], ['madre_externa_id', 'externo'], ['padre_externo_id', 'externo']] as [$campo, $tipoPadre]) {
                    $parentId = $fila->{$campo} ?? null;
                    if ($parentId === null) {
                        continue;
                    }
                    $clave = $tipoPadre[0].':'.$parentId;
                    $vistos[$clave] = true;
                    $siguiente[$clave] = ['tipo' => $tipoPadre, 'id' => $parentId];
                }
            }
            $frontera = array_values($siguiente);
        }

        return $vistos;
    }

    /**
     * Fecha estimada de concepción desde el estado proyectado: tacto con días (`tacto_fecha − dias`) o, si no, la del
     * servicio/IA (inicio del servicio natural: el extremo que da la gestación más larga). Null si no hay dato.
     */
    public static function concepcion(object $estado): ?Carbon
    {
        if (! in_array($estado->estado_reproductivo, ['servida', 'prenada'], true)) {
            return null;
        }
        if ($estado->estado_reproductivo === 'prenada' && $estado->tacto_fecha !== null && $estado->dias_gestacion_al_tacto !== null) {
            return Carbon::parse($estado->tacto_fecha)->subDays((int) $estado->dias_gestacion_al_tacto);
        }

        return $estado->servicio_fecha !== null ? Carbon::parse($estado->servicio_fecha) : null;
    }

    /**
     * TPA-29: 0..3 crías (modelo-datos §5); las vivas se dan de alta con una categoría de cría (`categorias.es_cria`) y, si
     * declaran madre, es la hembra del parto.
     */
    private function parto(ContextoEvento $ctx, stdClass $p): void
    {
        if (count((array) $p->crias) > 3) {
            throw new EventoRechazado('esquema_invalido', 'Un parto tiene hasta 3 crías.', ['payload.crias' => ['Máximo 3.']]);
        }
        $madre = $ctx->sobre->sujetos->animal_ids[0] ?? null;
        foreach ((array) $p->crias as $i => $c) {
            if (! ($c->viva ?? false) || ! isset($c->animal)) {
                continue;
            }
            if (! DB::table('categorias')->where('codigo', $c->animal->categoria)->value('es_cria')) {
                throw new EventoRechazado('regla_bloqueante', 'La cría nace con una categoría de cría.', ["payload.crias.{$i}.animal.categoria" => ['No es una categoría de cría.']]);
            }
            if (isset($c->animal->madre_id) && $c->animal->madre_id !== $madre) {
                throw new EventoRechazado('regla_bloqueante', 'La madre de la cría es la hembra del parto.', ["payload.crias.{$i}.animal.madre_id" => ['Distinta del sujeto.']]);
            }
        }
    }

    /** Aborto (y tacto): los días de gestación no superan la máxima de la especie/raza. */
    private function diasMaximos(ContextoEvento $ctx, stdClass $p): void
    {
        if (! isset($p->dias_gestacion)) {
            return;
        }
        foreach ($this->hembras($ctx) as $h) {
            $max = $this->gestacion('gestacion_max_dias', $ctx, $h);
            if ((int) $p->dias_gestacion > $max) {
                throw new EventoRechazado('regla_bloqueante', "La gestación de la especie no supera {$max} días.", ['payload.dias_gestacion' => ["Máximo {$max}."]]);
            }
        }
    }

    /**
     * TPA-29: el destete recategoriza en el acto con `categoria_nueva`, que no puede ser otra categoría de cría; en un lote
     * anónimo tiene que ser del sexo de cada categoría del conteo (un evento por sexo). Sin `categoria_nueva` queda la
     * sugerencia (motivo destete, TPA-25).
     */
    private function destete(ContextoEvento $ctx, stdClass $p): void
    {
        if (! isset($p->categoria_nueva)) {
            return;
        }
        $nueva = DB::table('categorias')->where('codigo', $p->categoria_nueva)->first();
        if ($nueva !== null && $nueva->es_cria) {
            throw new EventoRechazado('regla_bloqueante', 'Al destete la cría pasa a una categoría que no es de cría.', ['payload.categoria_nueva' => ['Es una categoría de cría.']]);
        }
        foreach (array_keys((array) ($ctx->sobre->sujetos->conteo ?? [])) as $cat) {
            $sexo = DB::table('categorias')->where('codigo', $cat)->value('sexo');
            if ($nueva !== null && $nueva->sexo !== null && $sexo !== null && $sexo !== $nueva->sexo) {
                throw new EventoRechazado('regla_bloqueante', 'La categoría nueva no corresponde al sexo del conteo: un destete por sexo.', ['payload.categoria_nueva' => ["Otro sexo que {$cat}."]]);
            }
        }
    }

    /**
     * TPA-28: preñada lleva días de gestación (o tamaño); los días no superan la gestación máxima de la especie/raza
     * (`gestacion_max_dias`, parámetro).
     */
    private function tacto(ContextoEvento $ctx, stdClass $p): void
    {
        if ($p->resultado !== 'prenada') {
            return;
        }
        if (! isset($p->dias_gestacion) && ! isset($p->tamano)) {
            throw new EventoRechazado('esquema_invalido', 'Una preñez lleva los días de gestación (o el tamaño).', ['payload.dias_gestacion' => ['Obligatorio si está preñada.']]);
        }
        $this->diasMaximos($ctx, $p);
    }

    /** Parámetro de gestación (días) resuelto por especie/raza/establecimiento a la fecha del evento. */
    private function gestacion(string $clave, ContextoEvento $ctx, object $hembra): int
    {
        return (int) Parametros::valor($clave, $hembra->especie_codigo, $hembra->raza_codigo, $ctx->est->id, $ctx->ocurridoEn());
    }

    /** TPA-27: período (fin ≥ inicio), al menos una hembra, toros machos enteros de la especie. */
    private function servicioNatural(ContextoEvento $ctx, stdClass $p): void
    {
        if (isset($p->fin) && $p->fin < $p->inicio) {
            throw new EventoRechazado('esquema_invalido', 'El fin del servicio es anterior al inicio.', ['payload.fin' => ['Anterior al inicio.']]);
        }
        if ($ctx->sobre->sujetos->tipo === 'animales' && $this->hembras($ctx) === []) {
            throw new EventoRechazado('regla_bloqueante', 'El servicio se registra sobre hembras.', ['sujetos.animal_ids' => ['Ninguna hembra.']]);
        }
        $especies = $this->especies($ctx);
        foreach ((array) ($p->toro_ids ?? []) as $i => $toroId) {
            $this->toro($ctx, $toroId, $especies, "payload.toro_ids.{$i}");
        }
        if (isset($p->lote_toros_id)) {
            $lote = DB::table('lotes')->where('id', $p->lote_toros_id)->where('establecimiento_id', $ctx->est->id)->first();
            if ($lote === null) {
                throw new EventoRechazado('dependencia_faltante', 'El lote de toros todavía no está en el servidor.');
            }
            if ($especies !== [] && ! in_array($lote->especie_codigo, $especies, true)) {
                throw new EventoRechazado('regla_bloqueante', 'El lote de toros es de otra especie.', ['payload.lote_toros_id' => ['Especie distinta de las hembras.']]);
            }
        }
    }

    /** TPA-27: semen de un toro del establecimiento (macho de la especie) o externo; raza del semen de la especie. */
    private function inseminacion(ContextoEvento $ctx, stdClass $p): void
    {
        $especies = $this->especies($ctx);
        if (isset($p->semen->toro_id)) {
            $this->toro($ctx, $p->semen->toro_id, $especies, 'payload.semen.toro_id', exigirEntero: false);
        }
        if (isset($p->semen->reproductor_externo_id)) {
            $externo = DB::table('reproductores_externos')->where('id', $p->semen->reproductor_externo_id)->where('establecimiento_id', $ctx->est->id)->whereNull('archivado_en')->first();
            if (! $externo) {
                throw new EventoRechazado('dependencia_faltante', 'El reproductor externo no está sincronizado.', ['payload.semen.reproductor_externo_id' => [$p->semen->reproductor_externo_id]]);
            }
            if ($especies !== [] && ! in_array($externo->especie_codigo, $especies, true)) {
                throw new EventoRechazado('regla_bloqueante', 'El reproductor externo es de otra especie.', ['payload.semen.reproductor_externo_id' => ['Especie distinta.']]);
            }
        }
        if (isset($p->semen->raza)) {
            $ok = DB::table('razas')->where('codigo', $p->semen->raza)->whereIn('especie_codigo', $especies)
                ->where(fn ($q) => $q->whereNull('establecimiento_id')->orWhere('establecimiento_id', $ctx->est->id))->exists();
            if (! $ok) {
                throw new EventoRechazado('regla_bloqueante', "La raza {$p->semen->raza} no es de la especie de la hembra.", ['payload.semen.raza' => ['Raza de otra especie o inexistente.']]);
            }
        }
    }

    /**
     * El toro existe en el establecimiento, es macho de la especie de las hembras y (en servicio natural) no está castrado.
     *
     * @param  list<string>  $especies
     */
    private function toro(ContextoEvento $ctx, string $toroId, array $especies, string $campo, bool $exigirEntero = true): void
    {
        $t = DB::table('animales as a')->join('animales_estado as e', 'e.animal_id', '=', 'a.id')
            ->where('a.id', $toroId)->where('a.establecimiento_id', $ctx->est->id)->first(['a.especie_codigo', 'e.sexo', 'e.castrado', 'e.estado']);
        if ($t === null) {
            throw new EventoRechazado('dependencia_faltante', 'El toro todavía no está en el servidor.', [$campo => [$toroId]]);
        }
        if ($t->sexo !== 'M' || ($exigirEntero && $t->castrado) || $t->estado === 'anulado') {
            throw new EventoRechazado('regla_bloqueante', 'El toro tiene que ser un macho entero.', [$campo => ['No es un macho entero.']]);
        }
        if ($especies !== [] && ! in_array($t->especie_codigo, $especies, true)) {
            throw new EventoRechazado('regla_bloqueante', 'El toro es de otra especie que las hembras.', [$campo => ['Especie distinta.']]);
        }
    }

    /** Estado proyectado de las hembras sujeto (las que ya existían). @return list<object> */
    private function hembras(ContextoEvento $ctx): array
    {
        $ids = array_keys($ctx->sujetosExistentes);
        if ($ids === []) {
            return [];
        }

        return DB::table('animales_estado')->whereIn('animal_id', $ids)->where('sexo', 'H')->orderBy('animal_id')->get()->all();
    }

    /** Especies de los sujetos (animales o lote). @return list<string> */
    private function especies(ContextoEvento $ctx): array
    {
        $especies = array_map(fn ($a) => $a->especie_codigo, array_values($ctx->sujetosExistentes));
        if ($ctx->lote !== null) {
            $especies[] = $ctx->lote->especie_codigo;
        }

        return array_values(array_unique($especies));
    }
}
