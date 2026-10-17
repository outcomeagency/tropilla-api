<?php

namespace App\Domain\Agregados;

use Illuminate\Support\Facades\DB;

/**
 * Dataset sintético del prototipo (TPA-75): establecimientos inventados del NEA con existencias, vacunación aftosa vigente y
 * ventas por trimestre. Determinístico (semilla fija) e idempotente por versión; **no deriva de ningún dato real** de la base.
 * Incluye a propósito celdas chicas (búfalos en Formosa/Misiones) y un aportante dominante para que la supresión trabaje.
 */
final class DatasetSintetico
{
    public const VERSION = 'sintetico-2026.10-v1';

    public const TRIMESTRES = ['2024T3', '2024T4', '2025T1', '2025T2', '2025T3', '2025T4', '2026T1', '2026T2'];

    /** Establecimientos sintéticos por provincia y especie. */
    private const POBLACION = [
        'BOV' => ['corrientes' => 70, 'chaco' => 40, 'formosa' => 28, 'misiones' => 16],
        'BUB' => ['corrientes' => 14, 'chaco' => 9, 'formosa' => 3, 'misiones' => 2],
    ];

    /** $/kg vivo de referencia por trimestre (serie inventada, solo forma). */
    private const PRECIO_BASE = [1750, 1900, 2050, 2250, 2450, 2600, 2800, 2950];

    public static function escala(int $cabezas): string
    {
        return $cabezas < 300 ? 'chico' : ($cabezas <= 1500 ? 'mediano' : 'grande');
    }

    /** Genera el dataset si falta. Devuelve la cantidad de filas de la versión. */
    public function asegurar(): int
    {
        if (! DB::table('agregados_sinteticos')->where('dataset_version', self::VERSION)->exists()) {
            DB::transaction(fn () => collect($this->filas())->chunk(500)->each(fn ($c) => DB::table('agregados_sinteticos')->insert($c->all())));
        }

        return DB::table('agregados_sinteticos')->where('dataset_version', self::VERSION)->count();
    }

    /** @return list<array<string, int|string>> */
    private function filas(): array
    {
        mt_srand(75);
        $filas = [];
        $n = 0;
        foreach (self::POBLACION as $especie => $provincias) {
            foreach ($provincias as $provincia => $cantidad) {
                for ($i = 0; $i < $cantidad; $i++) {
                    $n++;
                    $ref = sprintf('S-%04d', $n);
                    // Un criadero de búfalos dominante en Formosa: con tres aportantes, el mayor supera el 50 % de las cabezas.
                    $base = $especie === 'BUB' && $provincia === 'formosa' && $i === 0
                        ? 2400
                        : (int) max(40, round(exp(($especie === 'BOV' ? 6.3 : 5.3) + 0.9 * self::normal())));
                    $escala = self::escala($base);
                    $cobertura = 0.80 + 0.19 * self::uniforme();
                    foreach (self::TRIMESTRES as $t => $trimestre) {
                        $estacion = match (substr($trimestre, -1)) {
                            '4' => 1.06, '1' => 1.03, '2' => 0.96, default => 1.0,
                        };
                        $cabezas = (int) max(20, round($base * $estacion * (1 - 0.004 * $t) * (1 + 0.03 * self::normal())));
                        $vacunados = (int) min($cabezas, round($cabezas * min(1, $cobertura + 0.02 * self::normal())));
                        $vende = self::uniforme() < (substr($trimestre, -1) === '2' ? 0.6 : 0.45);
                        $kg = $vende ? (int) round($cabezas * (0.05 + 0.15 * self::uniforme()) * (330 + 90 * self::uniforme())) : 0;
                        $precio = self::PRECIO_BASE[$t] * ($especie === 'BUB' ? 0.88 : 1) * (1 + 0.06 * self::normal());
                        $filas[] = ['dataset_version' => self::VERSION, 'establecimiento_ref' => $ref, 'provincia' => $provincia, 'especie_codigo' => $especie,
                            'escala' => $escala, 'trimestre' => $trimestre, 'cabezas' => $cabezas, 'vacunados' => $vacunados, 'kg_vendidos' => $kg,
                            'importe_ventas' => (int) round($kg * $precio)];
                    }
                }
            }
        }

        return $filas;
    }

    private static function uniforme(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    /** Normal estándar (Box-Muller), acotada a ±3. */
    private static function normal(): float
    {
        $z = sqrt(-2 * log(max(1e-12, self::uniforme()))) * cos(2 * M_PI * self::uniforme());

        return max(-3, min(3, $z));
    }
}
