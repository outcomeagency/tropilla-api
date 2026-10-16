<?php

namespace App\Domain\Establecimientos;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Documentos\ConsultasDocumentos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\ConsultasEventos;
use App\Domain\Reproduccion\TableroReproductivo;
use App\Http\Resources\V1\Hacienda\EventoResumenResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Inicio (TPA-52, functional/04 §1): existencias por especie y categoría con **variación del mes**, pendientes de hoy/semana
 * (contados y como lista navegable) y actividad reciente con autor. Lo que cada uno ve depende del rol (consultas y
 * documentos fiscales solo dueño/admin). El estado de sincronización lo arma la app con su outbox.
 *
 * Variación del mes = flujos del mes por categoría: nacimientos, partos e ingresos (+), muertes, egresos, faenas y pérdidas (−),
 * y los movimientos entre categorías de los lotes anónimos. Las altas de inventario (stock inicial, importación, alta rápida)
 * **no** son variación del rodeo: es hacienda que ya estaba.
 */
final class Inicio
{
    private const SALIDAS = ['hacienda.muerte', 'hacienda.egreso', 'hacienda.faena', 'hacienda.perdida'];

    /** @return array<string, mixed> */
    public function armar(Establecimiento $est, User $u, ?string $especie, Request $request): array
    {
        $hoy = Carbon::now($est->zona_horaria)->startOfDay();
        $puede = fn (string $ab) => Gate::forUser($u)->allows($ab, $est);
        $locale = app()->getLocale();

        // Existencias + variación.
        $variacion = $this->variacion($est, $hoy->copy()->startOfMonth());
        $existencias = DB::table('existencias as x')->join('categorias as c', 'c.codigo', '=', 'x.categoria_codigo')
            ->where('x.establecimiento_id', $est->id)->when($especie, fn ($q) => $q->where('x.especie_codigo', $especie))
            ->orderBy('x.especie_codigo')->orderBy('c.orden')->get(['x.*', 'c.nombres']);
        $filas = $existencias->map(fn ($x) => ['especie' => $x->especie_codigo, 'categoria' => $x->categoria_codigo,
            'categoria_nombre' => (string) Catalogos::nombre($x->nombres, $locale), 'cabezas' => (int) $x->cabezas, 'individuales' => (int) $x->individuales,
            'anonimas' => (int) $x->anonimas, 'variacion_mes' => $variacion[$x->especie_codigo][$x->categoria_codigo] ?? 0])->all();
        // Categorías que se vaciaron este mes también informan su variación.
        foreach ($variacion as $esp => $cats) {
            foreach ($cats as $cat => $n) {
                if ($n !== 0 && (! $especie || $especie === $esp) && ! $existencias->contains(fn ($x) => $x->especie_codigo === $esp && $x->categoria_codigo === $cat)) {
                    $filas[] = ['especie' => $esp, 'categoria' => $cat, 'categoria_nombre' => (string) Catalogos::nombre(DB::table('categorias')->where('codigo', $cat)->value('nombres'), $locale),
                        'cabezas' => 0, 'individuales' => 0, 'anonimas' => 0, 'variacion_mes' => $n];
                }
            }
        }

        // Pendientes.
        $semana = $hoy->copy()->addDays(7)->toDateString();
        $items = [];
        $tareas = DB::table('tareas')->where('establecimiento_id', $est->id)->where('estado', 'pendiente')->where('vence_en', '<=', $semana)->orderBy('vence_en')->get();
        foreach ($tareas->take(10) as $t) {
            $items[] = ['tipo' => $t->vence_en < $hoy->toDateString() ? 'tarea_vencida' : 'tarea', 'titulo' => $t->titulo, 'fecha' => $t->vence_en, 'ruta' => '/sanidad/tareas', 'entidad_id' => $t->id];
        }
        $repro = collect(app(TableroReproductivo::class)->tablero($est, $especie));
        $partos = app(TableroReproductivo::class)->partos($est, ['especie' => $especie, 'hasta' => $semana]);
        foreach (array_slice($partos, 0, 10) as $p) {
            $items[] = ['tipo' => $p['vencido'] ? 'parto_vencido' : 'parto_proximo', 'titulo' => trim(($p['fila']->identificacion_principal ?? '').' — parto probable'),
                'fecha' => $p['fecha_probable_parto'], 'ruta' => '/reproduccion/partos', 'entidad_id' => $p['fila']->animal_id];
        }
        $recat = DB::table('animales_estado')->where('establecimiento_id', $est->id)->where('estado', 'activo')->whereNotNull('categoria_sugerida_codigo')
            ->whereColumn('categoria_sugerida_codigo', '<>', 'categoria_codigo')->when($especie, fn ($q) => $q->where('especie_codigo', $especie))->count();
        if ($recat > 0) {
            $items[] = ['tipo' => 'recategorizacion', 'titulo' => "{$recat} ".($recat === 1 ? 'animal' : 'animales').' con categoría sugerida', 'fecha' => null, 'ruta' => '/hacienda?sugerencia_categoria=true', 'entidad_id' => null];
        }
        $docs = $puede('documentos.ver') ? ConsultasDocumentos::base($est->id, $puede('documentos.fiscal'))->where('d.estado', 'vigente')->whereNotNull('d.vence_en')
            ->where('d.vence_en', '<=', $hoy->copy()->addDays(30)->toDateString())->orderBy('d.vence_en')->get(['d.id', 'd.titulo', 'd.vence_en']) : collect();
        foreach ($docs->take(10) as $d) {
            $items[] = ['tipo' => $d->vence_en < $hoy->toDateString() ? 'documento_vencido' : 'documento_por_vencer', 'titulo' => $d->titulo, 'fecha' => $d->vence_en, 'ruta' => '/documentos/'.$d->id, 'entidad_id' => $d->id];
        }
        $consultas = $puede('consultas.ver') ? DB::table('consultas')->where('establecimiento_id', $est->id)->where('estado', 'nueva')->count() : 0;
        if ($consultas > 0) {
            $items[] = ['tipo' => 'consulta', 'titulo' => "{$consultas} ".($consultas === 1 ? 'consulta nueva' : 'consultas nuevas'), 'fecha' => null, 'ruta' => '/comercial/consultas', 'entidad_id' => null];
        }
        $tomas = $puede('contratos.gestionar') && DB::getSchemaBuilder()->hasTable('tomas')
            ? DB::table('tomas')->where('establecimiento_id', $est->id)->where('estado', 'pago_informado')->count() : 0;
        if ($tomas > 0) {
            $items[] = ['tipo' => 'toma', 'titulo' => "{$tomas} ".($tomas === 1 ? 'anticipo informado por confirmar' : 'anticipos informados por confirmar'), 'fecha' => null, 'ruta' => '/comercial/contratos', 'entidad_id' => null];
        }
        $revisiones = DB::table('revisiones')->where('establecimiento_id', $est->id)->where('estado', 'abierta')->count();
        if ($revisiones > 0) {
            $items[] = ['tipo' => 'revision', 'titulo' => "{$revisiones} ".($revisiones === 1 ? 'carga para revisar' : 'cargas para revisar'), 'fecha' => null, 'ruta' => '/revisiones', 'entidad_id' => null];
        }

        // Actividad reciente (por fecha de carga), con autor.
        $actividad = ConsultasEventos::base()->where('v.establecimiento_id', $est->id)
            ->when($especie, fn ($q) => $q->where(fn ($w) => $w->whereExists(fn ($e) => $e->from('evento_animales as ea')->join('animales as an', 'an.id', '=', 'ea.animal_id')
                ->whereColumn('ea.evento_id', 'v.id')->where('an.especie_codigo', $especie))->orWhereExists(fn ($e) => $e->from('lotes as lo')->whereColumn('lo.id', 'v.lote_id')->where('lo.especie_codigo', $especie))))
            ->reorder()->orderByDesc('v.registrado_en')->orderByDesc('v.id')->limit(10)->get();

        return [
            'existencias' => array_values($filas),
            'pendientes' => [
                'tareas_sanitarias' => $tareas->count(),
                'partos_proximos' => (int) $repro->sum('partos_proximos'),
                'partos_vencidos' => (int) $repro->sum('partos_vencidos'),
                'recategorizaciones' => $recat,
                'documentos_por_vencer' => $docs->count(),
                'consultas_nuevas' => $consultas,
                'tomas_por_confirmar' => $tomas,
                'revisiones_abiertas' => $revisiones,
                'items' => $items,
            ],
            'actividad' => EventoResumenResource::collection($actividad)->toArray($request),
        ];
    }

    /** @return array<string, array<string, int>> especie => categoria => variación */
    private function variacion(Establecimiento $est, Carbon $desdeLocal): array
    {
        $desde = $desdeLocal->copy()->utc();
        $v = [];
        $suma = function (string $esp, string $cat, int $n) use (&$v) {
            $v[$esp][$cat] = ($v[$esp][$cat] ?? 0) + $n;
        };
        $eventos = DB::table('eventos_vigentes as e')->leftJoin('lotes as l', 'l.id', '=', 'e.lote_id')->where('e.establecimiento_id', $est->id)
            ->where('e.accion', '<>', 'anulacion')->where('e.ocurrido_en', '>=', $desde)
            ->whereIn('e.tipo', [...self::SALIDAS, 'hacienda.nacimiento', 'reproduccion.parto', 'hacienda.ingreso', 'hacienda.recategorizacion', 'reproduccion.destete'])
            ->get(['e.id', 'e.tipo', 'e.payload', 'e.conteo', 'e.sujeto_tipo', 'l.especie_codigo as lote_especie']);
        foreach ($eventos as $e) {
            $p = json_decode($e->payload, true) ?? [];
            $conteo = $e->conteo ? json_decode($e->conteo, true) : null;
            if (in_array($e->tipo, ['hacienda.nacimiento', 'reproduccion.parto', 'hacienda.ingreso'], true)) {
                if ($e->sujeto_tipo === 'lote' && $conteo) {
                    foreach ($conteo as $cat => $n) {
                        $suma((string) $e->lote_especie, $cat, (int) $n);
                    }
                }
                foreach ($p['animales'] ?? [] as $a) {
                    $suma($a['especie'], $a['categoria'], 1);
                }
                foreach ($p['crias'] ?? [] as $c) {
                    if (isset($c['animal']) && ($c['viva'] ?? true)) {
                        $suma($c['animal']['especie'], $c['animal']['categoria'], 1);
                    }
                }
            } elseif (in_array($e->tipo, self::SALIDAS, true)) {
                if ($e->sujeto_tipo === 'lote' && $conteo) {
                    foreach ($conteo as $cat => $n) {
                        $suma((string) $e->lote_especie, $cat, -(int) $n);
                    }
                }
                $bajas = DB::table('evento_animales as ea')->join('animales_estado as a', 'a.animal_id', '=', 'ea.animal_id')->where('ea.evento_id', $e->id)
                    ->get(['a.especie_codigo', 'a.categoria_codigo']);
                foreach ($bajas as $b) {
                    $suma($b->especie_codigo, $b->categoria_codigo, -1);
                }
            } else {
                foreach ($p['movimientos'] ?? [] as $m) {
                    $suma((string) $e->lote_especie, $m['de'], -(int) $m['cantidad']);
                    $suma((string) $e->lote_especie, $m['a'], (int) $m['cantidad']);
                }
            }
        }

        return $v;
    }
}
