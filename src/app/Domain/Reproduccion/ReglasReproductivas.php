<?php

namespace App\Domain\Reproduccion;

use App\Domain\Hacienda\Eventos\ContextoEvento;
use App\Domain\Hacienda\Eventos\EventoRechazado;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reglas del módulo Reproducción (functional/02 §2.2, modelo-datos §6.3) que `RegistrarEvento` aplica a los
 * eventos `reproduccion.*`: bloqueantes (§7.4 paso 8) y de coherencia (paso 10, advertencias). Los umbrales
 * salen de `parametros` por especie/raza/establecimiento; nada de `if especie`.
 */
final class ReglasReproductivas
{
    /** Bloqueantes: se llaman después de validar sujetos y referencias genéricas (no para anulaciones). */
    public function validar(ContextoEvento $ctx): void
    {
        $p = $ctx->sobre->payload ?? new stdClass;
        match ($ctx->sobre->tipo) {
            'reproduccion.servicio_natural' => $this->servicioNatural($ctx, $p),
            'reproduccion.inseminacion' => $this->inseminacion($ctx, $p),
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
        }

        return $obs;
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
