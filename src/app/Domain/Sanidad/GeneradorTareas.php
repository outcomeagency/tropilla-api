<?php

namespace App\Domain\Sanidad;

use App\Domain\Catalogos\Parametros;
use App\Domain\Establecimientos\Interdiccion;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Proyecciones\CondicionEvaluator;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sanidad\Models\PlanItem;
use App\Domain\Sanidad\Models\Tarea;
use App\Domain\Sync\Actions\RegistrarCambio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Genera las tareas del plan sanitario con horizonte de N días (parámetro `plan_horizonte_dias`, 90). Idempotente:
 * cada ocurrencia tiene una `clave_ocurrencia` única por ítem; al regenerar se **sincroniza** el conjunto deseado:
 * - nueva ocurrencia → tarea pendiente;
 * - misma clave con otras fechas (se editó el ítem) → se actualizan las fechas, salvo que el usuario la haya reprogramado;
 * - tarea pendiente futura que ya no corresponde (ítem inactivo/archivado, condición de zona que dejó de cumplirse, animal
 *   que salió) → `omitida` con motivo. Lo vencido y lo hecho no se toca.
 *
 * Disparadores (`plan_items.disparador`): `fecha_fija {fecha: MM-DD}` · `ventana {desde, hasta: MM-DD}` o `{meses: [4,5]}` ·
 * `relativo {evento: nacimiento, dias | semanas[]}` (una por animal) · `periodico {cada_dias, desde?}` · `manual` (nada).
 * Repetición: `refuerzo_dias {min, max}` (tras una primera dosis de la enfermedad), `repetir_dias` (segunda ventana),
 * `hasta: destete` (solo no destetados).
 */
final class GeneradorTareas
{
    public function __construct(private readonly RegistrarCambio $registrarCambio) {}

    public function generar(Establecimiento $est): int
    {
        return DB::transaction(function () use ($est) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            $hoy = Carbon::parse(Carbon::now($est->zona_horaria)->toDateString()); // fecha local como día «naive» (UTC 00:00)
            $horizonte = (int) (Parametros::valor('plan_horizonte_dias', null, null, $est->id) ?? 90);
            $hasta = $hoy->copy()->addDays($horizonte);
            $hechos = self::hechos($est);
            $cambios = 0;

            $items = PlanItem::query()->where('establecimiento_id', $est->id)->get();
            foreach ($items as $item) {
                $vigente = $item->activo && $item->archivado_en === null && self::aplica($item, $est, $hechos);
                $deseadas = $vigente ? $this->ocurrencias($item, $est, $hoy, $hasta) : [];
                $existentes = Tarea::query()->where('plan_item_id', $item->id)->get()->keyBy('clave_ocurrencia');

                foreach ($deseadas as $clave => $o) {
                    $t = $existentes->get($clave);
                    if ($t === null) {
                        $t = Tarea::query()->create([
                            'establecimiento_id' => $est->id, 'plan_item_id' => $item->id, 'clave_ocurrencia' => $clave, 'titulo' => $item->nombre,
                            'tipo_evento' => $item->tipo_evento, 'enfermedad' => $item->enfermedad, 'producto_id' => $item->producto_id,
                            'alcance' => $o['alcance'], 'fecha_desde' => $o['desde'], 'fecha_hasta' => $o['hasta'], 'vence_en' => $o['vence'],
                            'origen_evento_id' => $o['origen'] ?? null, 'cabezas_estimadas' => AlcanceSanitario::cabezas($est->id, $item->especie_codigo, $o['alcance']),
                        ]);
                        $this->registrarCambio->handle($est->id, 'tarea', $t->id, cadena: $cadena);
                        $cambios++;

                        continue;
                    }
                    if ($t->estado !== 'pendiente' || $t->reprogramada) {
                        continue;
                    }
                    $nuevo = ['titulo' => $item->nombre, 'tipo_evento' => $item->tipo_evento, 'enfermedad' => $item->enfermedad, 'producto_id' => $item->producto_id,
                        'alcance' => $o['alcance'], 'fecha_desde' => $o['desde'], 'fecha_hasta' => $o['hasta'], 'vence_en' => $o['vence'],
                        'cabezas_estimadas' => AlcanceSanitario::cabezas($est->id, $item->especie_codigo, $o['alcance'])];
                    $t->fill($nuevo);
                    if ($t->isDirty()) {
                        $t->row_version++;
                        $t->save();
                        $this->registrarCambio->handle($est->id, 'tarea', $t->id, cadena: $cadena);
                        $cambios++;
                    }
                }

                foreach ($existentes as $clave => $t) {
                    if ($t->estado === 'pendiente' && ! isset($deseadas[$clave]) && $t->vence_en->toDateString() >= $hoy->toDateString()) {
                        $t->forceFill(['estado' => 'omitida', 'omitida_motivo' => $vigente ? 'Ya no corresponde según el plan.' : 'El ítem del plan no está vigente.',
                            'omitida_en' => now(), 'row_version' => $t->row_version + 1])->save();
                        $this->registrarCambio->handle($est->id, 'tarea', $t->id, cadena: $cadena);
                        $cambios++;
                    }
                }
            }

            return $cambios;
        });
    }

    /** Hechos del establecimiento para el DSL de condiciones (modelo-datos §3.2). @return array<string, mixed> */
    public static function hechos(Establecimiento $est): array
    {
        $h = ['interdiccion_vigente' => Interdiccion::vigente($est->id) !== null, 'actividades' => $est->actividades];
        foreach ((array) $est->zonas_sanitarias as $k => $v) {
            $h["zona.{$k}"] = $v;
        }

        return $h;
    }

    /** ¿El ítem aplica a este establecimiento? (especie declarada + condición de zona). @param array<string, mixed> $hechos */
    public static function aplica(PlanItem $item, Establecimiento $est, array $hechos): bool
    {
        if ($item->especie_codigo !== null && ! in_array($item->especie_codigo, $est->especies, true)) {
            return false;
        }

        return $item->condicion === null || $item->condicion === []
            || CondicionEvaluator::evaluar($item->condicion, $hechos, fn ($p) => Parametros::valor($p, $item->especie_codigo, null, $est->id));
    }

    /** ¿Faltan fechas para poder generar (ventana/fecha configurable sin configurar)? */
    public static function requiereConfiguracion(PlanItem $item): bool
    {
        $d = $item->disparador;

        return match ($d['tipo'] ?? 'manual') {
            'fecha_fija' => empty($d['fecha']),
            'ventana' => empty($d['meses']) && (empty($d['desde']) || empty($d['hasta'])),
            default => false,
        };
    }

    /** @return array<string, array{desde: ?string, hasta: ?string, vence: string, alcance: array<string, mixed>, origen?: string}> */
    private function ocurrencias(PlanItem $item, Establecimiento $est, Carbon $hoy, Carbon $hasta): array
    {
        $d = $item->disparador;
        $r = $item->repeticion ?? [];
        $alcance = $item->alcance ?: [];
        if (($r['hasta'] ?? null) === 'destete') {
            $alcance['no_destetados'] = true;
        }
        $out = [];
        $anios = [$hoy->year, $hoy->year + 1];

        switch ($d['tipo'] ?? 'manual') {
            case 'fecha_fija':
                if (! empty($d['fecha'])) {
                    foreach ($anios as $y) {
                        $f = self::mmdd($y, $d['fecha']);
                        if ($f->betweenIncluded($hoy, $hasta)) {
                            $out[(string) $y] = ['desde' => null, 'hasta' => null, 'vence' => $f->toDateString(), 'alcance' => $alcance];
                        }
                    }
                }
                break;
            case 'ventana':
                [$md1, $md2] = ! empty($d['meses'])
                    ? [sprintf('%02d-01', min($d['meses'])), Carbon::create(2001, max($d['meses']), 1)->endOfMonth()->format('m-d')]
                    : [$d['desde'] ?? null, $d['hasta'] ?? null];
                if ($md1 && $md2) {
                    foreach ($anios as $y) {
                        $ini = self::mmdd($y, $md1);
                        $fin = self::mmdd($md2 < $md1 ? $y + 1 : $y, $md2);
                        $ventanas = [(string) $y => [$ini, $fin]];
                        if (! empty($r['repetir_dias'])) {
                            $ventanas["{$y}-rep"] = [$ini->copy()->addDays((int) $r['repetir_dias']), $fin->copy()->addDays((int) $r['repetir_dias'])];
                        }
                        foreach ($ventanas as $clave => [$a, $b]) {
                            if ($b->gte($hoy) && $a->lte($hasta)) {
                                $out[$clave] = ['desde' => $a->toDateString(), 'hasta' => $b->toDateString(), 'vence' => $b->toDateString(), 'alcance' => $alcance];
                            }
                        }
                    }
                }
                break;
            case 'relativo':
                $offsets = isset($d['semanas']) ? array_map(fn ($s) => (int) $s * 7, (array) $d['semanas']) : [(int) ($d['dias'] ?? 0)];
                if (($d['evento'] ?? null) === 'nacimiento') {
                    $min = $hoy->copy()->subDays(max($offsets))->toDateString();
                    $animales = AlcanceSanitario::individuales($est->id, $item->especie_codigo, ['animal_ids' => $alcance['animal_ids'] ?? null])
                        ->whereNotNull('e.fecha_nacimiento')->where('e.fecha_nacimiento', '>=', $min)->pluck('e.fecha_nacimiento', 'e.animal_id');
                    foreach ($animales as $id => $nac) {
                        foreach ($offsets as $off) {
                            $f = Carbon::parse($nac)->addDays($off);
                            if ($f->betweenIncluded($hoy, $hasta)) {
                                $out["animal:{$id}:nac+{$off}"] = ['desde' => null, 'hasta' => null, 'vence' => $f->toDateString(), 'alcance' => ['animal_ids' => [$id]]];
                            }
                        }
                    }
                }
                break;
            case 'periodico':
                $cada = max(1, (int) ($d['cada_dias'] ?? 30));
                $f = Carbon::parse($d['desde'] ?? $item->created_at->copy()->setTimezone($est->zona_horaria)->toDateString());
                if ($f->lt($hoy)) {
                    $f->addDays((int) (ceil($f->diffInDays($hoy, true) / $cada) * $cada));
                }
                if (AlcanceSanitario::cabezas($est->id, $item->especie_codigo, $alcance) > 0) {
                    for (; $f->lte($hasta); $f->addDays($cada)) {
                        $out['per:'.$f->toDateString()] = ['desde' => null, 'hasta' => null, 'vence' => $f->toDateString(), 'alcance' => $alcance];
                    }
                }
                break;
        }

        // Refuerzo tras una primera dosis de la misma enfermedad (p. ej. rabia: 20–60 días).
        if (isset($r['refuerzo_dias']['min'], $r['refuerzo_dias']['max']) && $item->enfermedad !== null) {
            $desde = Carbon::parse($hoy->toDateString(), $est->zona_horaria)->subDays((int) $r['refuerzo_dias']['max'])->utc();
            $primeras = DB::table('eventos_vigentes')->where('establecimiento_id', $est->id)->where('tipo', 'sanidad.vacunacion')
                ->where('accion', '<>', 'anulacion')->where('ocurrido_en', '>=', $desde)
                ->whereRaw("payload->>'enfermedad' = ? AND payload->>'numero_dosis' = 'primera'", [$item->enfermedad])->get(['id', 'evento_raiz_id', 'ocurrido_en', 'lote_id']);
            foreach ($primeras as $ev) {
                $f = Carbon::parse(Carbon::parse($ev->ocurrido_en)->setTimezone($est->zona_horaria)->toDateString());
                $a = $f->copy()->addDays((int) $r['refuerzo_dias']['min']);
                $b = $f->copy()->addDays((int) $r['refuerzo_dias']['max']);
                if ($b->gte($hoy)) {
                    $ids = DB::table('evento_animales')->where('evento_id', $ev->id)->orderBy('animal_id')->pluck('animal_id')->all();
                    $out["refuerzo:{$ev->evento_raiz_id}"] = ['desde' => $a->toDateString(), 'hasta' => $b->toDateString(), 'vence' => $b->toDateString(),
                        'alcance' => $ids !== [] ? ['animal_ids' => $ids] : ['lote_ids' => array_filter([$ev->lote_id])], 'origen' => $ev->evento_raiz_id];
                }
            }
        }

        return $out;
    }

    private static function mmdd(int $anio, string $mmdd): Carbon
    {
        [$m, $d] = array_map('intval', explode('-', $mmdd));

        return Carbon::create($anio, $m, min($d, Carbon::create($anio, $m, 1)->daysInMonth))->startOfDay();
    }
}
