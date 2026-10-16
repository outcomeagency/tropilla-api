<?php

namespace App\Domain\Clima\Actions;

use App\Domain\Clima\EvaluadorAlertas;
use App\Domain\Clima\FuentesClima;
use App\Domain\Clima\UbicacionClima;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Notificaciones\Notificador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recalcula el clima de un establecimiento (TPA-70): consulta las fuentes (con cache), evalúa las reglas efectivas y persiste
 * alertas + estado. Una alerta que sigue vigente se actualiza (mismo id, sin volver a avisar); la que el pronóstico ya no sostiene
 * queda `descartada` (no se borra). Si una fuente falla, sus reglas no se evalúan y las alertas previas se conservan.
 * Avisa por notificaciones in-app/push (Notificador) solo las alertas nuevas que no son informativas.
 */
final class ActualizarClima
{
    public const ROLES_AVISO = ['dueno', 'admin', 'encargado', 'asesor'];

    public function __construct(private readonly FuentesClima $fuentes, private readonly EvaluadorAlertas $evaluador, private readonly Notificador $notificador) {}

    /** @return int alertas nuevas */
    public function handle(Establecimiento $est, bool $avisar = true): int
    {
        $ahora = now();
        $ubic = UbicacionClima::resolver($est);
        if ($ubic === null) {
            $this->guardarEstado($est, 'sin_ubicacion', '', [], null, null, [['fuente' => 'ubicacion', 'mensaje' => 'El establecimiento no tiene provincia o departamento reconocidos.']], $ahora);

            return 0;
        }

        $tz = $est->zona_horaria;
        $hoy = Carbon::now($tz)->toDateString();
        $reglas = self::reglasEfectivas($est->id);
        $errores = [];
        $pron = $this->intentar('open_meteo_forecast', fn () => $this->fuentes->pronostico($ubic['lat'], $ubic['lon'], $tz), $errores);
        $hist = null;
        $sequia = collect($reglas)->firstWhere('tipo', 'sequia');
        if ($sequia !== null) {
            $hasta = Carbon::parse($hoy)->subDays(6);
            $desde = $hasta->copy()->subYearsNoOverflow((int) $sequia['parametros']['anios_referencia'])->subDays((int) $sequia['parametros']['ventana_dias'] + 5);
            $hist = $this->intentar('open_meteo_archive', fn () => $this->fuentes->historico($ubic['lat'], $ubic['lon'], $tz, $desde, $hasta), $errores);
        }
        $oni = collect($reglas)->contains('tipo', 'enso') ? $this->intentar('noaa_cpc_oni', fn () => $this->fuentes->oni(), $errores) : null;

        $r = $this->evaluador->evaluar($reglas, $hoy, $pron['dias'] ?? null, $hist['lluvia'] ?? null, $oni);
        $emitida = ['open_meteo_forecast' => $pron['obtenido_en'] ?? null, 'open_meteo_archive' => $hist['obtenido_en'] ?? null, 'noaa_cpc_oni' => $oni['obtenido_en'] ?? null];
        $enso = $oni === null ? null : ['fase' => $oni['oni'] >= 0.5 ? 'el_nino' : ($oni['oni'] <= -0.5 ? 'la_nina' : 'neutral'), 'oni' => $oni['oni'],
            'temporada' => $oni['temporada'], 'anio' => $oni['anio'], 'obtenido_en' => $oni['obtenido_en']];
        $zona = $ubic['zona'];
        $recs = array_column($reglas, 'recomendacion', 'codigo');

        $nuevas = DB::transaction(function () use ($est, $r, $emitida, $zona, $ahora, $hoy, $ubic, $pron, $enso, $errores, $recs) {
            $nuevas = [];
            $vigentes = [];
            foreach ($r['alertas'] as $a) {
                $fila = ['tipo' => $a['tipo'], 'severidad' => $a['severidad'], 'titulo' => $a['titulo'], 'detalle' => $a['detalle'], 'recomendacion' => $recs[$a['regla_codigo']],
                    'fuente' => FuentesClima::FUENTES[$a['fuente']]['nombre'], 'fuente_url' => FuentesClima::FUENTES[$a['fuente']]['url'], 'zona' => $zona,
                    'valor' => $a['valor'], 'umbral' => $a['umbral'], 'unidad' => $a['unidad'], 'desde' => $a['desde'], 'hasta' => $a['hasta'], 'horizonte_dias' => $a['horizonte_dias'],
                    'estado' => 'activa', 'emitida_en' => Carbon::parse($emitida[$a['fuente']] ?? $ahora), 'calculada_en' => $ahora, 'updated_at' => $ahora];
                // Misma regla con fechas que se tocan = misma alerta (el pronóstico se corre un día): se actualiza sin volver a avisar.
                $previa = DB::table('clima_alertas')->where('establecimiento_id', $est->id)->where('regla_codigo', $a['regla_codigo'])->where('estado', 'activa')
                    ->where('hasta', '>=', Carbon::parse($a['desde'])->subDay()->toDateString())->where('desde', '<=', Carbon::parse($a['hasta'])->addDay()->toDateString())
                    ->orderByDesc('created_at')->first();
                if ($previa !== null) {
                    DB::table('clima_alertas')->where('id', $previa->id)->update($fila);
                    $vigentes[] = $previa->id;
                } else {
                    $id = (string) Str::uuid7();
                    DB::table('clima_alertas')->insert(['id' => $id, 'establecimiento_id' => $est->id, 'regla_codigo' => $a['regla_codigo'], ...$fila, 'created_at' => $ahora]);
                    $vigentes[] = $id;
                    $nuevas[] = ['id' => $id, ...$fila];
                }
            }
            DB::table('clima_alertas')->where('establecimiento_id', $est->id)->where('estado', 'activa')->whereIn('regla_codigo', $r['evaluadas'])
                ->whereNotIn('id', $vigentes)->where('hasta', '>=', $hoy)->update(['estado' => 'descartada', 'updated_at' => $ahora]);
            $this->guardarEstado($est, $ubic['precision'], $zona, $pron['dias'] ?? [], $r['lluvia'], $enso, $errores, $ahora);

            return $nuevas;
        });

        if ($avisar) {
            foreach ($nuevas as $a) {
                if ($a['severidad'] === 'info') {
                    continue;
                }
                $this->notificador->aRoles($est->id, self::ROLES_AVISO, 'clima_alerta', $a['titulo'], "{$zona}: {$a['detalle']}",
                    ['alerta_id' => $a['id'], 'tipo_alerta' => $a['tipo'], 'ruta' => '/clima'], "clima:{$a['id']}");
            }
        }

        return count($nuevas);
    }

    /**
     * Reglas activas con el override del establecimiento aplicado (parámetros mezclados sobre los globales).
     *
     * @return list<array{codigo: string, tipo: string, nombre: string, severidad: string, parametros: array<string, mixed>, recomendacion: string}>
     */
    public static function reglasEfectivas(string $establecimientoId, bool $incluirInactivas = false): array
    {
        $filas = DB::table('clima_reglas')->where(fn ($q) => $q->whereNull('establecimiento_id')->orWhere('establecimiento_id', $establecimientoId))
            ->orderBy('id')->get()->groupBy('codigo');
        $salida = [];
        foreach ($filas as $codigo => $grupo) {
            $global = $grupo->firstWhere('establecimiento_id', null);
            $propia = $grupo->firstWhere('establecimiento_id', $establecimientoId);
            if ($global === null) {
                continue;
            }
            $activa = (bool) ($propia->activa ?? $global->activa);
            if (! $activa && ! $incluirInactivas) {
                continue;
            }
            $salida[] = ['codigo' => (string) $codigo, 'tipo' => $global->tipo, 'nombre' => $global->nombre, 'severidad' => $propia->severidad ?? $global->severidad,
                'parametros' => array_replace(json_decode($global->parametros, true), $propia ? json_decode($propia->parametros, true) : []),
                'recomendacion' => $global->recomendacion, 'activa' => $activa, 'personalizada' => $propia !== null];
        }

        return $salida;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn  @param list<array{fuente: string, mensaje: string}> $errores
     * @return T|null
     */
    private function intentar(string $fuente, callable $fn, array &$errores): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Log::warning('clima: fuente no disponible', ['fuente' => $fuente, 'error' => $e->getMessage()]);
            $errores[] = ['fuente' => $fuente, 'mensaje' => 'Fuente no disponible en este momento; se muestran los últimos datos calculados.'];

            return null;
        }
    }

    /** @param list<array<string, mixed>> $pronostico @param array<string, mixed>|null $lluvia @param array<string, mixed>|null $enso @param list<array<string, string>> $errores */
    private function guardarEstado(Establecimiento $est, string $precision, string $zona, array $pronostico, ?array $lluvia, ?array $enso, array $errores, Carbon $ahora): void
    {
        $previo = DB::table('clima_estado')->where('establecimiento_id', $est->id)->first();
        // Si una fuente falló, se conserva lo último que se obtuvo de ella.
        DB::table('clima_estado')->updateOrInsert(['establecimiento_id' => $est->id], [
            'precision' => $precision, 'zona' => $zona,
            'pronostico' => $pronostico === [] && $previo ? $previo->pronostico : json_encode($pronostico),
            'lluvia' => $lluvia === null ? $previo?->lluvia : json_encode($lluvia),
            'enso' => $enso === null ? $previo?->enso : json_encode($enso),
            'errores' => json_encode($errores), 'calculado_en' => $ahora, 'updated_at' => $ahora, 'created_at' => $previo->created_at ?? $ahora,
        ]);
    }
}
