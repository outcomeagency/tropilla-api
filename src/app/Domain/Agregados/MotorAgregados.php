<?php

namespace App\Domain\Agregados;

use Illuminate\Support\Facades\DB;

/**
 * Motor de agregación con control de divulgación (TPA-75, negocio-datos §1.6 y §5).
 *
 * Para cada (métrica, especie, trimestre) arma el **cubo completo** NEA/provincia × total/escala, aplica supresión primaria
 * (celdas con menos de k establecimientos o con un aportante que supera el % de dominancia) y supresión complementaria
 * (ningún total publicado permite despejar por resta una celda o un conjunto inseguro). Toda consulta recorta ese mismo cubo:
 * cruzar consultas no revela más que el cubo publicado. No hay filtros libres, sumas entre trimestres ni "todas las especies".
 */
final class MotorAgregados
{
    public const METRICAS = ['existencias', 'cobertura_sanitaria', 'precios'];

    public const PROVINCIAS = ['corrientes' => 'Corrientes', 'chaco' => 'Chaco', 'formosa' => 'Formosa', 'misiones' => 'Misiones'];

    public const ESCALAS = ['chico', 'mediano', 'grande'];

    public const ESPECIES = ['BOV', 'BUB'];

    /** @var array<string, list<object>> */
    private array $filas = [];

    /**
     * @return array<string, array{geo: string, escala: string, n: int, peso: float, max: float, num: float, refs: list<string>, motivo: ?string}>
     */
    public function cubo(string $metrica, string $especie, string $trimestre, object $politica, bool $complementaria = true): array
    {
        $geos = ['nea', ...array_keys(self::PROVINCIAS)];
        $escalas = $politica->desglose_escala ? ['todas', ...self::ESCALAS] : ['todas'];
        $celdas = [];
        foreach ($geos as $g) {
            foreach ($escalas as $e) {
                $celdas["{$g}|{$e}"] = ['geo' => $g, 'escala' => $e, 'n' => 0, 'peso' => 0.0, 'max' => 0.0, 'num' => 0.0, 'refs' => [], 'motivo' => null];
            }
        }
        foreach ($this->filas($especie, $trimestre) as $f) {
            [$peso, $num] = match ($metrica) {
                'existencias' => [(float) $f->cabezas, (float) $f->cabezas],
                'cobertura_sanitaria' => [(float) $f->cabezas, (float) $f->vacunados],
                'precios' => [(float) $f->kg_vendidos, (float) $f->importe_ventas],
            };
            if ($peso <= 0) {
                continue;
            }
            foreach (['nea|todas', "{$f->provincia}|todas", "nea|{$f->escala}", "{$f->provincia}|{$f->escala}"] as $k) {
                if (isset($celdas[$k])) {
                    $celdas[$k]['n']++;
                    $celdas[$k]['peso'] += $peso;
                    $celdas[$k]['num'] += $num;
                    $celdas[$k]['max'] = max($celdas[$k]['max'], $peso);
                    $celdas[$k]['refs'][] = $f->establecimiento_ref;
                }
            }
        }

        foreach ($celdas as $k => $c) {
            $celdas[$k]['motivo'] = $c['n'] === 0 ? 'sin_datos' : self::riesgo($c['n'], $c['peso'], $c['max'], $politica);
        }
        if ($complementaria) {
            $this->complementaria($celdas, $politica);
        }

        return $celdas;
    }

    /** Motivo de supresión primaria (o null si la celda es publicable). */
    public static function riesgo(int $n, float $peso, float $max, object $politica): ?string
    {
        if ($n < $politica->k_minimo) {
            return 'celda_chica';
        }

        return $peso > 0 && $max / $peso * 100 > $politica->dominancia_max_pct ? 'dominancia' : null;
    }

    /**
     * Relaciones aditivas publicadas: total = Σ partes (NEA = Σ provincias; total de una zona = Σ escalas).
     *
     * @param  array<string, array<string, mixed>>  $celdas
     * @return list<array{padre: string, hijos: list<string>}>
     */
    public static function grupos(array $celdas): array
    {
        $escalas = array_values(array_unique(array_column($celdas, 'escala')));
        $grupos = [];
        foreach ($escalas as $e) {
            $grupos[] = ['padre' => "nea|{$e}", 'hijos' => array_map(fn ($g) => "{$g}|{$e}", array_keys(self::PROVINCIAS))];
        }
        if (count($escalas) > 1) {
            foreach (['nea', ...array_keys(self::PROVINCIAS)] as $g) {
                $grupos[] = ['padre' => "{$g}|todas", 'hijos' => array_map(fn ($e) => "{$g}|{$e}", self::ESCALAS)];
            }
        }

        return $grupos;
    }

    /**
     * Si el padre se publica, lo que se puede despejar por resta es el conjunto de hijos suprimidos: tiene que ser seguro
     * (al menos dos celdas, k establecimientos y sin dominancia). Si el padre se suprime, no puede quedar como único suprimido.
     *
     * @param  array<string, array<string, mixed>>  $celdas
     * @param  array{padre: string, hijos: list<string>}  $grupo
     */
    public static function despejable(array $celdas, array $grupo, object $politica): bool
    {
        $suprimidos = array_values(array_filter($grupo['hijos'], fn ($h) => $celdas[$h]['motivo'] !== null && $celdas[$h]['n'] > 0));
        if ($celdas[$grupo['padre']]['motivo'] !== null) {
            return $suprimidos === [] && $celdas[$grupo['padre']]['n'] > 0;
        }
        if ($suprimidos === []) {
            return false;
        }
        $n = array_sum(array_map(fn ($h) => $celdas[$h]['n'], $suprimidos));
        $peso = array_sum(array_map(fn ($h) => $celdas[$h]['peso'], $suprimidos));
        $max = max(array_map(fn ($h) => $celdas[$h]['max'], $suprimidos));

        return count($suprimidos) === 1 || self::riesgo($n, $peso, $max, $politica) !== null;
    }

    /** @param array<string, array<string, mixed>> $celdas */
    private function complementaria(array &$celdas, object $politica): void
    {
        $grupos = self::grupos($celdas);
        do {
            $cambio = false;
            foreach ($grupos as $grupo) {
                if (! self::despejable($celdas, $grupo, $politica)) {
                    continue;
                }
                $candidatos = array_values(array_filter($grupo['hijos'], fn ($h) => $celdas[$h]['motivo'] === null && $celdas[$h]['n'] > 0));
                usort($candidatos, fn ($a, $b) => [$celdas[$a]['n'], $a] <=> [$celdas[$b]['n'], $b]);
                $objetivo = $candidatos[0] ?? $grupo['padre'];
                if ($celdas[$objetivo]['motivo'] === null) {
                    $celdas[$objetivo]['motivo'] = 'complementaria';
                    $cambio = true;
                }
            }
        } while ($cambio);
    }

    /**
     * Valor publicado de una celda (redondeado) o null si está suprimida o vacía.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, int>|null
     */
    public static function valores(string $metrica, array $c, object $politica): ?array
    {
        if ($c['motivo'] !== null || $c['n'] === 0) {
            return null;
        }
        $establecimientos = (int) (round($c['n'] / 5) * 5);

        return match ($metrica) {
            'existencias' => ['establecimientos' => $establecimientos, 'cabezas' => (int) (round($c['peso'] / $politica->redondeo_cabezas) * $politica->redondeo_cabezas)],
            'cobertura_sanitaria' => ['establecimientos' => $establecimientos, 'cobertura_pct' => (int) round($c['num'] / $c['peso'] * 100)],
            'precios' => ['establecimientos' => $establecimientos, 'precio_kg' => (int) (round($c['num'] / $c['peso'] / 10) * 10)],
        };
    }

    /** @return list<object> */
    private function filas(string $especie, string $trimestre): array
    {
        return $this->filas["{$especie}|{$trimestre}"] ??= DB::table('agregados_sinteticos')
            ->where('dataset_version', DatasetSintetico::VERSION)->where('especie_codigo', $especie)->where('trimestre', $trimestre)
            ->orderBy('establecimiento_ref')->get(['establecimiento_ref', 'provincia', 'escala', 'cabezas', 'vacunados', 'kg_vendidos', 'importe_ventas'])->all();
    }
}
