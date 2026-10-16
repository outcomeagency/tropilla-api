<?php

namespace App\Domain\Hacienda\Eventos;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Models\Evento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Estado de trabajo de un sobre mientras se valida y registra. */
final class ContextoEvento
{
    public ?object $lote = null;

    /** @var list<stdClass> animal_nuevo[] del payload */
    public array $nuevos = [];

    /** @var array<string, object> animales sujeto que ya existían (con su estado proyectado) */
    public array $sujetosExistentes = [];

    /** @var list<string> animales creados por la versión base (correcciones de altas) */
    public array $creadosPorBase = [];

    /** @var list<string> animales que el servidor resuelve para sujetos no individuales (potrero en sanidad, TPA-32) */
    public array $animalesResueltos = [];

    /** Canal de carga (`web` | `push`): algunas reglas bloquean online y solo observan offline. */
    public string $canal = 'web';

    /** @var array<string, mixed> datos calculados por las reglas de dominio durante la validación */
    public array $extra = [];

    public function __construct(
        public readonly Establecimiento $est,
        public readonly stdClass $sobre,
        public readonly object $tipo,
        public readonly ?Evento $base,
    ) {}

    /**
     * Si el sobre trae solo fecha: esa fecha a las 12:00 de la zona del establecimiento (modelo-datos §4.4).
     */
    public function ocurridoEn(): Carbon
    {
        $o = $this->sobre->ocurrido_en;

        return strlen($o) === 10
            ? Carbon::parse($o.' 12:00:00', $this->est->zona_horaria)->utc()
            : Carbon::parse($o)->utc();
    }

    /** Animales de esta versión: sujetos + creados (crías del parto, altas). @return list<string> */
    public function animalIdsDelEvento(): array
    {
        $ids = array_merge((array) ($this->sobre->sujetos->animal_ids ?? []), array_column($this->nuevos, 'animal_id'), $this->creadosPorBase, $this->animalesResueltos);

        return array_values(array_unique($ids));
    }

    /** Animales a recalcular: los de esta versión y los de las versiones anteriores del mismo evento. @return list<string> */
    public function animalesAfectados(): array
    {
        $ids = $this->animalIdsDelEvento();
        if ($this->base !== null) {
            $previos = DB::table('evento_animales as ea')->join('eventos as e', 'e.id', '=', 'ea.evento_id')
                ->where('e.evento_raiz_id', $this->base->evento_raiz_id)->pluck('ea.animal_id')->all();
            $ids = array_merge($ids, $previos);
        }

        return array_values(array_unique($ids));
    }

    /** Lotes a recalcular (sujeto, destino, lotes de los animales nuevos, versiones previas). @return list<string> */
    public function lotesAfectados(): array
    {
        $p = $this->sobre->payload ?? new stdClass;
        $ids = array_filter([$this->sobre->sujetos->lote_id ?? null, $p->lote_destino_id ?? null]);
        foreach ($this->nuevos as $n) {
            if (isset($n->lote_id)) {
                $ids[] = $n->lote_id;
            }
        }
        if ($this->base !== null) {
            foreach (DB::table('eventos')->where('evento_raiz_id', $this->base->evento_raiz_id)->get(['lote_id', 'payload']) as $v) {
                $ids[] = $v->lote_id;
                $ids[] = json_decode($v->payload, true)['lote_destino_id'] ?? null;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
