<?php

namespace App\Domain\Clima;

use App\Domain\Establecimientos\Data\Provincias;
use App\Domain\Establecimientos\Models\Establecimiento;
use Illuminate\Support\Facades\DB;

/**
 * Punto para consultar el pronóstico (TPA-70), del más preciso al más general: ubicación del establecimiento → centroide del
 * departamento → capital de la provincia. La ubicación exacta es privada: a las fuentes se envía redondeada a 2 decimales
 * (~1 km) y la API nunca la devuelve; la zona se informa por nombre.
 */
final class UbicacionClima
{
    /** Capitales provinciales (lat, lon), respaldo cuando no hay ubicación ni centroide del departamento. */
    private const CAPITALES = [
        '02' => [-34.61, -58.38], '06' => [-34.92, -57.95], '10' => [-28.47, -65.78], '14' => [-31.42, -64.18], '18' => [-27.47, -58.83],
        '22' => [-27.45, -58.99], '26' => [-43.30, -65.10], '30' => [-31.73, -60.53], '34' => [-26.18, -58.17], '38' => [-24.19, -65.30],
        '42' => [-36.62, -64.29], '46' => [-29.41, -66.86], '50' => [-32.89, -68.83], '54' => [-27.37, -55.90], '58' => [-38.95, -68.06],
        '62' => [-40.81, -63.00], '66' => [-24.79, -65.41], '70' => [-31.54, -68.54], '74' => [-33.30, -66.34], '78' => [-51.62, -69.22],
        '82' => [-31.63, -60.70], '86' => [-27.78, -64.26], '90' => [-26.81, -65.22], '94' => [-54.80, -68.30],
    ];

    /** @return array{lat: float, lon: float, precision: string, zona: string}|null */
    public static function resolver(Establecimiento $est): ?array
    {
        $provincia = Provincias::TODAS[$est->provincia_codigo] ?? null;
        $depto = DB::table('geo_departamentos')->where('codigo', $est->departamento_codigo)
            ->first(['nombre', DB::raw('ST_Y(centroide::geometry) AS lat'), DB::raw('ST_X(centroide::geometry) AS lon')]);
        $zona = trim(($depto?->nombre ? "Depto. {$depto->nombre}, " : '').($provincia ?? ''), ', ');

        if ($est->ubicacion !== null) {
            return ['lat' => round((float) $est->ubicacion['lat'], 2), 'lon' => round((float) $est->ubicacion['lon'], 2), 'precision' => 'establecimiento',
                'zona' => $zona !== '' ? "{$est->nombre} ({$zona})" : $est->nombre];
        }
        if ($depto !== null && $depto->lat !== null) {
            return ['lat' => round((float) $depto->lat, 2), 'lon' => round((float) $depto->lon, 2), 'precision' => 'departamento', 'zona' => $zona];
        }
        if (isset(self::CAPITALES[$est->provincia_codigo])) {
            [$lat, $lon] = self::CAPITALES[$est->provincia_codigo];

            return ['lat' => $lat, 'lon' => $lon, 'precision' => 'provincia', 'zona' => (string) $provincia];
        }

        return null;
    }
}
