<?php

namespace App\Domain\Clima;

/**
 * Reglas climáticas por defecto (TPA-70). Son **datos**: la migración las siembra en `clima_reglas` y cada establecimiento puede
 * sobrescribir umbrales o desactivarlas (`PUT /establecimientos/{id}/clima/reglas/{codigo}`). Umbrales iniciales orientativos para el
 * NEA, a validar con agronomía/meteorología. Las recomendaciones son puntos a revisar, no indicaciones de manejo.
 */
final class ReglasPredeterminadas
{
    public const TODAS = [
        [
            'codigo' => 'lluvia_extrema', 'tipo' => 'lluvia', 'nombre' => 'Lluvia extrema / riesgo de anegamiento', 'severidad' => 'alta',
            'parametros' => ['mm_dia' => 80, 'mm_72h' => 120, 'horizonte_dias' => 7],
            'recomendacion' => 'Para revisar: potreros bajos o anegables, caminos y accesos para mover hacienda, y que las reservas de alimento queden en un lugar alto y accesible.',
        ],
        [
            'codigo' => 'sequia_deficit', 'tipo' => 'sequia', 'nombre' => 'Sequía por déficit de lluvia acumulado', 'severidad' => 'moderada',
            'parametros' => ['ventana_dias' => 60, 'anomalia_max_pct' => 60, 'lluvia_pronostico_max_mm' => 15, 'horizonte_dias' => 7, 'anios_referencia' => 10],
            'recomendacion' => 'Para revisar: estado de aguadas y represas, inventario de reservas (rollos, silo, suplemento) y, con tu asesor, si la carga actual se sostiene.',
        ],
        [
            'codigo' => 'helada', 'tipo' => 'helada', 'nombre' => 'Helada', 'severidad' => 'moderada',
            'parametros' => ['tmin_c' => 0, 'horizonte_dias' => 7],
            'recomendacion' => 'Para revisar: reservas de forraje para los días de helada, reparo para terneros y categorías sensibles, y que los bebederos no se congelen.',
        ],
        [
            'codigo' => 'calor_estres', 'tipo' => 'calor', 'nombre' => 'Ola de calor / estrés térmico', 'severidad' => 'moderada',
            'parametros' => ['thi_min' => 79, 'dias_consecutivos' => 3, 'horizonte_dias' => 7],
            'recomendacion' => 'Para revisar: sombra y agua de bebida suficientes (y revolcaderos donde haya búfalos); conviene no programar trabajos de manga ni arreos en las horas de más calor.',
        ],
        [
            'codigo' => 'incendio', 'tipo' => 'incendio', 'nombre' => 'Condiciones favorables a incendios', 'severidad' => 'alta',
            'parametros' => ['tmax_c' => 30, 'hr_min_max_pct' => 30, 'viento_kmh' => 30, 'lluvia_7d_max_mm' => 5, 'horizonte_dias' => 5],
            'recomendacion' => 'Para revisar: cortafuegos y picadas, evitar quemas, y tener identificadas aguadas y salidas para la hacienda.',
        ],
        [
            'codigo' => 'enso_fase', 'tipo' => 'enso', 'nombre' => 'Fase ENSO (El Niño / La Niña)', 'severidad' => 'info',
            'parametros' => ['oni_umbral' => 0.5, 'horizonte_dias' => 90],
            'recomendacion' => 'Es una señal estacional regional, no un pronóstico para tu campo. Sirve para conversar con tu asesor la planificación de agua y reservas de la temporada.',
        ],
    ];

    /** Parámetros numéricos admitidos por regla (para validar overrides). @return list<string> */
    public static function parametros(string $codigo): array
    {
        foreach (self::TODAS as $r) {
            if ($r['codigo'] === $codigo) {
                return array_keys($r['parametros']);
            }
        }

        return [];
    }
}
