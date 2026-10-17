<?php

namespace App\Domain\Agregados;

/**
 * Prueba de reidentificación sobre **todo** lo publicable (3 métricas × 2 especies × 8 trimestres) bajo una política.
 * Simula los ataques de negocio-datos §1.6/§5: celda chica, aportante dominante, diferenciación por resta entre totales y partes,
 * vinculación con conocimiento externo (perfil público: provincia + especie + escala) y entrada/salida de un solo aportante
 * entre trimestres (advertencia: queda mitigada por redondeo, no eliminada). Aprobada solo si no hay fallas.
 */
final class PruebaReidentificacion
{
    public function __construct(private readonly MotorAgregados $motor) {}

    /** @return array{resultado: string, controles: list<array<string, mixed>>} */
    public function ejecutar(object $politica, bool $complementaria = true): array
    {
        $control = fn (string $codigo, string $titulo) => ['codigo' => $codigo, 'titulo' => $titulo, 'evaluadas' => 0, 'fallas' => 0, 'ejemplos' => []];
        $chica = $control('celda_chica', __('Ninguna celda publicada tiene menos de :k establecimientos', ['k' => $politica->k_minimo]));
        $dominancia = $control('dominancia', __('Ningún aportante supera el :p % de una celda publicada', ['p' => $politica->dominancia_max_pct]));
        $resta = $control('diferenciacion', __('Restar partes publicadas de un total no despeja una celda ni un grupo inseguro'));
        $vinculo = $control('vinculacion', __('Con el perfil público de un campo (provincia, especie y escala) no se aísla su celda'));
        $temporal = $control('temporal', __('Entre trimestres no entra ni sale un único aportante de una celda publicada'));

        foreach (MotorAgregados::METRICAS as $metrica) {
            foreach (MotorAgregados::ESPECIES as $especie) {
                $anterior = null;
                foreach (DatasetSintetico::TRIMESTRES as $trimestre) {
                    $cubo = $this->motor->cubo($metrica, $especie, $trimestre, $politica, $complementaria);
                    $donde = fn (string $k) => "{$metrica} · {$especie} · {$trimestre} · {$k}";
                    $expuestos = [];
                    foreach ($cubo as $k => $c) {
                        if ($c['n'] === 0) {
                            continue;
                        }
                        $chica['evaluadas']++;
                        $dominancia['evaluadas']++;
                        if ($c['motivo'] === null && $c['n'] < $politica->k_minimo) {
                            self::falla($chica, $donde($k));
                        }
                        if ($c['motivo'] === null && $politica->dominancia_max_pct < $c['max'] / $c['peso'] * 100) {
                            self::falla($dominancia, $donde($k));
                        }
                        if ($c['motivo'] === null && $c['n'] < $politica->k_minimo) {
                            array_push($expuestos, ...$c['refs']);
                        }
                        if ($anterior !== null && $c['motivo'] === null && $anterior[$k]['motivo'] === null) {
                            $temporal['evaluadas']++;
                            if (count(array_diff($c['refs'], $anterior[$k]['refs'])) + count(array_diff($anterior[$k]['refs'], $c['refs'])) === 1) {
                                self::falla($temporal, $donde($k));
                            }
                        }
                    }
                    foreach (MotorAgregados::grupos($cubo) as $grupo) {
                        $resta['evaluadas']++;
                        if (MotorAgregados::despejable($cubo, $grupo, $politica)) {
                            self::falla($resta, $donde($grupo['padre']));
                        }
                        // Lo que se despeja por resta (padre publicado − partes publicadas) también es un conjunto conocido.
                        if ($cubo[$grupo['padre']]['motivo'] === null) {
                            $despejado = array_merge(...array_map(fn ($h) => $cubo[$h]['motivo'] !== null ? $cubo[$h]['refs'] : [], $grupo['hijos']));
                            if ($despejado !== [] && count($despejado) < $politica->k_minimo) {
                                array_push($expuestos, ...$despejado);
                            }
                        }
                    }
                    $vinculo['evaluadas'] += count(array_unique(array_merge(...array_map(fn ($c) => $c['refs'], array_values($cubo)))));
                    foreach (array_unique($expuestos) as $ref) {
                        self::falla($vinculo, $donde($ref));
                    }
                    $anterior = $cubo;
                }
            }
        }

        $temporal['advertencias'] = $temporal['fallas'];
        $temporal['fallas'] = 0;
        $temporal['mitigacion'] = __('Valores redondeados (cabezas a :r, precio a $10/kg, establecimientos a 5): la diferencia entre trimestres no devuelve el dato exacto del aportante. Revisar antes de sumar datos reales.', ['r' => $politica->redondeo_cabezas]);
        $controles = [$chica, $dominancia, $resta, $vinculo, $temporal];

        return ['resultado' => array_sum(array_column($controles, 'fallas')) === 0 ? 'aprobada' : 'fallida', 'controles' => $controles];
    }

    /** @param array<string, mixed> $control */
    private static function falla(array &$control, string $donde): void
    {
        $control['fallas']++;
        if (count($control['ejemplos']) < 5) {
            $control['ejemplos'][] = $donde;
        }
    }
}
