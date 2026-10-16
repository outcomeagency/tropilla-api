<?php

namespace App\Domain\Clima;

use Illuminate\Support\Carbon;

/**
 * Reglas explícitas (TPA-70): cada regla de `clima_reglas` se evalúa con sus parámetros contra el pronóstico diario, la lluvia observada
 * y el ONI. Función pura: devuelve alertas candidatas y qué reglas pudieron evaluarse (si una fuente falló, la regla no se evalúa y sus
 * alertas previas no se descartan). Todo texto aclara que es pronóstico, no certeza.
 */
final class EvaluadorAlertas
{
    public const AVISO = 'Es un pronóstico, no una certeza: puede cambiar con las próximas actualizaciones.';

    /**
     * @param  list<array{codigo: string, tipo: string, nombre: string, severidad: string, parametros: array<string, mixed>}>  $reglas
     * @param  list<array<string, mixed>>|null  $dias  pronóstico diario (incluye días pasados del análisis)
     * @param  array<string, float>|null  $historico  lluvia diaria observada por fecha
     * @param  array{temporada: string, anio: int, oni: float}|null  $oni
     * @return array{alertas: list<array<string, mixed>>, evaluadas: list<string>, lluvia: array<string, mixed>|null}
     */
    public function evaluar(array $reglas, string $hoy, ?array $dias, ?array $historico, ?array $oni): array
    {
        $alertas = [];
        $evaluadas = [];
        $lluvia = null;
        $futuro = $dias === null ? null : array_values(array_filter($dias, fn ($d) => $d['fecha'] >= $hoy));
        $pasado = $dias === null ? null : array_values(array_filter($dias, fn ($d) => $d['fecha'] < $hoy));
        if ($futuro === []) {
            $futuro = null;
        }

        foreach ($reglas as $r) {
            $p = $r['parametros'];
            $h = (int) ($p['horizonte_dias'] ?? 7);
            $ventana = $futuro === null ? null : array_slice($futuro, 0, $h);
            $a = match ($r['tipo']) {
                'lluvia' => $ventana === null ? false : $this->lluvia($p, $ventana),
                'helada' => $ventana === null ? false : $this->helada($p, $ventana),
                'calor' => $ventana === null ? false : $this->calor($p, $ventana),
                'incendio' => $ventana === null ? false : $this->incendio($p, $ventana, $pasado ?? []),
                'sequia' => $historico === null || $futuro === null ? false : $this->sequia($p, $hoy, $h, $historico, $futuro, $lluvia),
                'enso' => $oni === null ? false : $this->enso($p, $hoy, $h, $oni),
                default => false,
            };
            if ($a === false) {
                continue; // no evaluable: falta la fuente
            }
            $evaluadas[] = $r['codigo'];
            if ($a !== null) {
                $alertas[] = ['regla_codigo' => $r['codigo'], 'tipo' => $r['tipo'], 'severidad' => $r['severidad'], 'horizonte_dias' => $h, ...$a];
            }
        }

        return ['alertas' => $alertas, 'evaluadas' => $evaluadas, 'lluvia' => $lluvia];
    }

    /** Índice temperatura-humedad (THI) con la máxima del día y la humedad mínima (aprox. de la hora más cálida). */
    public static function thi(float $t, float $hr): float
    {
        return round((1.8 * $t + 32) - (0.55 - 0.0055 * $hr) * (1.8 * $t - 26), 1);
    }

    /** @param array<string, mixed> $p @param list<array<string, mixed>> $v @return array<string, mixed>|null */
    private function lluvia(array $p, array $v): ?array
    {
        $max1 = ['mm' => 0.0, 'i' => 0];
        $max3 = ['mm' => 0.0, 'i' => 0];
        foreach ($v as $i => $d) {
            $mm = (float) ($d['lluvia_mm'] ?? 0);
            if ($mm > $max1['mm']) {
                $max1 = ['mm' => $mm, 'i' => $i];
            }
            $s = array_sum(array_map(fn ($x) => (float) ($x['lluvia_mm'] ?? 0), array_slice($v, $i, 3)));
            if ($s > $max3['mm']) {
                $max3 = ['mm' => $s, 'i' => $i];
            }
        }
        $porDia = $max1['mm'] >= $p['mm_dia'];
        if (! $porDia && $max3['mm'] < $p['mm_72h']) {
            return null;
        }
        $desde = $v[$porDia ? $max1['i'] : $max3['i']]['fecha'];
        $hasta = $porDia ? $desde : $v[min(count($v) - 1, $max3['i'] + 2)]['fecha'];
        $prob = max(array_map(fn ($d) => (int) ($d['prob_lluvia'] ?? 0), $v));
        [$valor, $umbral] = $porDia ? [$max1['mm'], $p['mm_dia']] : [$max3['mm'], $p['mm_72h']];
        $periodo = $porDia ? 'en un día' : 'en 72 h';

        return ['titulo' => 'Pronóstico de lluvia fuerte: '.self::n($valor)." mm {$periodo}",
            'detalle' => 'Entre el '.self::f($desde).' y el '.self::f($hasta).' el pronóstico acumula '.self::n($valor)." mm {$periodo} (umbral ".self::n($umbral).' mm)'
                .($prob > 0 ? ", con probabilidad de lluvia de hasta {$prob} %" : '').'. '.self::AVISO,
            'valor' => $valor, 'umbral' => $umbral, 'unidad' => 'mm', 'desde' => $desde, 'hasta' => $hasta, 'fuente' => 'open_meteo_forecast'];
    }

    /** @param array<string, mixed> $p @param list<array<string, mixed>> $v @return array<string, mixed>|null */
    private function helada(array $p, array $v): ?array
    {
        $dias = array_values(array_filter($v, fn ($d) => $d['tmin'] !== null && $d['tmin'] <= $p['tmin_c']));
        if ($dias === []) {
            return null;
        }
        $min = min(array_column($dias, 'tmin'));
        $n = count($dias);

        return ['titulo' => 'Pronóstico de helada: mínima de '.self::n($min).' °C',
            'detalle' => "{$n} ".($n === 1 ? 'día' : 'días').' con mínima de '.self::n($p['tmin_c']).' °C o menos entre el '.self::f($dias[0]['fecha']).' y el '.self::f($dias[$n - 1]['fecha'])
                .'. A nivel del pasto puede helar con mínimas algo mayores. '.self::AVISO,
            'valor' => $min, 'umbral' => $p['tmin_c'], 'unidad' => '°C', 'desde' => $dias[0]['fecha'], 'hasta' => $dias[$n - 1]['fecha'], 'fuente' => 'open_meteo_forecast'];
    }

    /** @param array<string, mixed> $p @param list<array<string, mixed>> $v @return array<string, mixed>|null */
    private function calor(array $p, array $v): ?array
    {
        $racha = [];
        $mejor = [];
        foreach ($v as $d) {
            $thi = $d['tmax'] !== null && $d['hr_min'] !== null ? self::thi((float) $d['tmax'], (float) $d['hr_min']) : null;
            if ($thi !== null && $thi >= $p['thi_min']) {
                $racha[] = $d + ['thi' => $thi];
                if (count($racha) > count($mejor)) {
                    $mejor = $racha;
                }
            } else {
                $racha = [];
            }
        }
        if (count($mejor) < $p['dias_consecutivos']) {
            return null;
        }
        $max = max(array_column($mejor, 'thi'));
        $n = count($mejor);

        return ['titulo' => "Ola de calor: {$n} días seguidos con estrés térmico",
            'detalle' => 'Del '.self::f($mejor[0]['fecha']).' al '.self::f($mejor[$n - 1]['fecha']).' el índice temperatura-humedad (THI) pronosticado llega a '.self::n($max)
                .' (umbral '.self::n($p['thi_min']).', máximas de hasta '.self::n(max(array_column($mejor, 'tmax'))).' °C). '.self::AVISO,
            'valor' => $max, 'umbral' => $p['thi_min'], 'unidad' => 'THI', 'desde' => $mejor[0]['fecha'], 'hasta' => $mejor[$n - 1]['fecha'], 'fuente' => 'open_meteo_forecast'];
    }

    /**
     * Índice simple: días secos, calurosos y ventosos después de una semana casi sin lluvia.
     *
     * @param  array<string, mixed>  $p  @param list<array<string, mixed>> $v @param list<array<string, mixed>> $pasado
     * @return array<string, mixed>|null
     */
    private function incendio(array $p, array $v, array $pasado): ?array
    {
        $previa = array_sum(array_map(fn ($d) => (float) ($d['lluvia_mm'] ?? 0), array_slice($pasado, -7)));
        if ($previa > $p['lluvia_7d_max_mm']) {
            return null;
        }
        $dias = array_values(array_filter($v, fn ($d) => $d['tmax'] !== null && $d['hr_min'] !== null && $d['viento_max'] !== null
            && $d['tmax'] >= $p['tmax_c'] && $d['hr_min'] <= $p['hr_min_max_pct'] && $d['viento_max'] >= $p['viento_kmh'] && (float) ($d['lluvia_mm'] ?? 0) < 1));
        if ($dias === []) {
            return null;
        }
        $n = count($dias);
        $hrMin = min(array_column($dias, 'hr_min'));

        return ['titulo' => 'Condiciones favorables a incendios',
            'detalle' => "{$n} ".($n === 1 ? 'día' : 'días').' entre el '.self::f($dias[0]['fecha']).' y el '.self::f($dias[$n - 1]['fecha']).' con máxima ≥ '.self::n($p['tmax_c'])
                .' °C, humedad mínima de hasta '.self::n($hrMin).' % y viento ≥ '.self::n($p['viento_kmh']).' km/h, tras '.self::n($previa).' mm en los últimos 7 días. '
                .'No es un foco detectado: es un índice simple de riesgo. '.self::AVISO,
            'valor' => $hrMin, 'umbral' => $p['hr_min_max_pct'], 'unidad' => '% HR', 'desde' => $dias[0]['fecha'], 'hasta' => $dias[$n - 1]['fecha'], 'fuente' => 'open_meteo_forecast'];
    }

    /**
     * Déficit acumulado: lluvia observada de la ventana vs. el promedio de la misma ventana en los años de referencia, y poca lluvia pronosticada.
     *
     * @param  array<string, mixed>  $p  @param array<string, float> $historico @param list<array<string, mixed>> $futuro
     * @param  array<string, mixed>|null  $resumen  (salida) resumen de lluvia para la vista
     * @return array<string, mixed>|null|false
     */
    private function sequia(array $p, string $hoy, int $h, array $historico, array $futuro, ?array &$resumen): array|null|false
    {
        if ($historico === []) {
            return false;
        }
        $ventana = (int) $p['ventana_dias'];
        $fin = Carbon::parse(max(array_keys($historico)));
        $suma = function (Carbon $hasta) use ($historico, $ventana): ?float {
            $s = 0.0;
            $n = 0;
            for ($d = $hasta->copy()->subDays($ventana - 1); $d->lte($hasta); $d->addDay()) {
                if (isset($historico[$d->toDateString()])) {
                    $s += $historico[$d->toDateString()];
                    $n++;
                }
            }

            return $n >= $ventana * 0.9 ? $s : null;
        };
        $actual = $suma($fin);
        $refs = [];
        for ($k = 1; $k <= (int) $p['anios_referencia']; $k++) {
            $s = $suma($fin->copy()->subYearsNoOverflow($k));
            if ($s !== null) {
                $refs[] = $s;
            }
        }
        if ($actual === null || count($refs) < 3) {
            return false;
        }
        $normal = array_sum($refs) / count($refs);
        $pct = $normal > 0 ? round($actual / $normal * 100) : 100.0;
        $prox = array_sum(array_map(fn ($d) => (float) ($d['lluvia_mm'] ?? 0), array_slice($futuro, 0, $h)));
        $resumen = ['ventana_dias' => $ventana, 'hasta' => $fin->toDateString(), 'acumulada_mm' => round($actual, 1), 'normal_mm' => round($normal, 1),
            'anomalia_pct' => $pct, 'anios_referencia' => count($refs), 'pronostico_mm' => round($prox, 1), 'pronostico_dias' => $h];
        if ($pct > $p['anomalia_max_pct'] || $prox > $p['lluvia_pronostico_max_mm']) {
            return null;
        }
        $hasta = Carbon::parse($hoy)->addDays($h - 1)->toDateString();

        return ['titulo' => "Déficit de lluvia: {$pct} % de lo normal en {$ventana} días",
            'detalle' => "En los últimos {$ventana} días (hasta el ".self::f($fin->toDateString()).') llovieron '.self::n($actual).' mm, frente a '.self::n($normal)
                .' mm de promedio en los '.count($refs).' años anteriores (umbral '.self::n($p['anomalia_max_pct']).' %), y el pronóstico de los próximos '
                ."{$h} días suma ".self::n($prox).' mm. Lluvia observada estimada por reanálisis, no por pluviómetro del campo. '.self::AVISO,
            'valor' => $pct, 'umbral' => $p['anomalia_max_pct'], 'unidad' => '%', 'desde' => $hoy, 'hasta' => $hasta, 'fuente' => 'open_meteo_archive'];
    }

    /** @param array<string, mixed> $p @param array{temporada: string, anio: int, oni: float} $oni @return array<string, mixed>|null */
    private function enso(array $p, string $hoy, int $h, array $oni): ?array
    {
        if (abs($oni['oni']) < $p['oni_umbral']) {
            return null;
        }
        $fase = $oni['oni'] > 0 ? 'El Niño' : 'La Niña';
        $tendencia = $oni['oni'] > 0 ? 'suele asociarse a lluvias por encima de lo normal en el NEA' : 'suele asociarse a lluvias por debajo de lo normal en el NEA';

        return ['titulo' => "Condiciones de {$fase} (ONI ".self::n($oni['oni']).')',
            'detalle' => "El índice ONI del trimestre {$oni['temporada']} {$oni['anio']} es ".self::n($oni['oni']).' (umbral ±'.self::n($p['oni_umbral'])."). {$fase} {$tendencia}, "
                .'pero es una tendencia estacional regional, no un pronóstico para tu campo.',
            'valor' => $oni['oni'], 'umbral' => $oni['oni'] > 0 ? $p['oni_umbral'] : -$p['oni_umbral'], 'unidad' => 'ONI', 'desde' => $hoy,
            'hasta' => Carbon::parse($hoy)->addDays($h - 1)->toDateString(), 'fuente' => 'noaa_cpc_oni'];
    }

    private static function n(float|int $v): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.'));
    }

    private static function f(string $fecha): string
    {
        return Carbon::parse($fecha)->format('d/m');
    }
}
