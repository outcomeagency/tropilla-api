<?php

namespace App\Domain\Clima;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fuentes gratuitas y sin key (TPA-70), con cache por punto redondeado (varios establecimientos cercanos comparten consulta):
 * - Open-Meteo Forecast: pronóstico diario a 16 días (modelos globales y regionales; CC BY 4.0, uso no comercial gratuito).
 * - Open-Meteo Historical (ERA5/ERA5-Land): lluvia observada (reanálisis, ~5 días de demora) para anomalías.
 * - NOAA CPC ONI: índice ENSO trimestral (dominio público). Señal estacional regional, no pronóstico local.
 * Licencia comercial de Open-Meteo: para producción con fines comerciales corresponde el plan pago (ver docs del ticket).
 */
final class FuentesClima
{
    public const FUENTES = [
        'open_meteo_forecast' => ['nombre' => 'Open-Meteo — pronóstico', 'url' => 'https://open-meteo.com/en/docs',
            'licencia' => 'CC BY 4.0 (datos); API gratuita para uso no comercial', 'cadencia' => 'Modelos actualizados cada 1–6 h; TROPILLA consulta cada 6 h'],
        'open_meteo_archive' => ['nombre' => 'Open-Meteo — histórico (reanálisis ERA5)', 'url' => 'https://open-meteo.com/en/docs/historical-weather-api',
            'licencia' => 'CC BY 4.0 (Copernicus ERA5)', 'cadencia' => 'Diaria, con ~5 días de demora'],
        'noaa_cpc_oni' => ['nombre' => 'NOAA CPC — índice ONI (ENSO)', 'url' => 'https://www.cpc.ncep.noaa.gov/products/analysis_monitoring/ensostuff/ONI_v5.php',
            'licencia' => 'Dominio público (gobierno de EE. UU.)', 'cadencia' => 'Mensual'],
    ];

    /**
     * @return array{obtenido_en: string, dias: list<array{fecha: string, tmax: ?float, tmin: ?float, lluvia_mm: ?float, prob_lluvia: ?int, hr_min: ?float, viento_max: ?float}>}
     */
    public function pronostico(float $lat, float $lon, string $tz): array
    {
        return Cache::remember("clima:pronostico:{$lat}:{$lon}", now()->addHours((int) config('tropilla.clima.cache_pronostico_horas')), function () use ($lat, $lon, $tz) {
            $j = $this->get(config('tropilla.clima.open_meteo_forecast_url'), [
                'latitude' => $lat, 'longitude' => $lon, 'timezone' => $tz, 'forecast_days' => 16, 'past_days' => 7,
                'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,relative_humidity_2m_min,wind_speed_10m_max',
            ]);
            $d = $j['daily'] ?? throw new RuntimeException('Open-Meteo: respuesta sin datos diarios.');
            $dias = [];
            foreach ($d['time'] ?? [] as $i => $fecha) {
                $dias[] = ['fecha' => $fecha, 'tmax' => self::num($d['temperature_2m_max'][$i] ?? null), 'tmin' => self::num($d['temperature_2m_min'][$i] ?? null),
                    'lluvia_mm' => self::num($d['precipitation_sum'][$i] ?? null), 'prob_lluvia' => isset($d['precipitation_probability_max'][$i]) ? (int) $d['precipitation_probability_max'][$i] : null,
                    'hr_min' => self::num($d['relative_humidity_2m_min'][$i] ?? null), 'viento_max' => self::num($d['wind_speed_10m_max'][$i] ?? null)];
            }

            return ['obtenido_en' => now()->toIso8601String(), 'dias' => $dias];
        });
    }

    /**
     * Lluvia diaria observada (reanálisis) desde `$desde` hasta `$hasta`.
     *
     * @return array{obtenido_en: string, lluvia: array<string, float>}
     */
    public function historico(float $lat, float $lon, string $tz, Carbon $desde, Carbon $hasta): array
    {
        return Cache::remember("clima:historico:{$lat}:{$lon}:{$desde->toDateString()}:{$hasta->toDateString()}", now()->addHours((int) config('tropilla.clima.cache_historico_horas')),
            function () use ($lat, $lon, $tz, $desde, $hasta) {
                $j = $this->get(config('tropilla.clima.open_meteo_archive_url'), [
                    'latitude' => $lat, 'longitude' => $lon, 'timezone' => $tz, 'start_date' => $desde->toDateString(), 'end_date' => $hasta->toDateString(), 'daily' => 'precipitation_sum',
                ]);
                $d = $j['daily'] ?? throw new RuntimeException('Open-Meteo histórico: respuesta sin datos diarios.');
                $lluvia = [];
                foreach ($d['time'] ?? [] as $i => $fecha) {
                    if (isset($d['precipitation_sum'][$i])) {
                        $lluvia[$fecha] = (float) $d['precipitation_sum'][$i];
                    }
                }

                return ['obtenido_en' => now()->toIso8601String(), 'lluvia' => $lluvia];
            });
    }

    /** @return array{obtenido_en: string, temporada: string, anio: int, oni: float} */
    public function oni(): array
    {
        return Cache::remember('clima:oni', now()->addHours((int) config('tropilla.clima.cache_enso_horas')), function () {
            $r = Http::timeout((int) config('tropilla.clima.timeout_segundos'))->retry(2, 500, throw: false)->get(config('tropilla.clima.noaa_oni_url'));
            if (! $r->successful()) {
                throw new RuntimeException("NOAA CPC: HTTP {$r->status()}.");
            }
            $ultima = null;
            foreach (preg_split('/\R/', trim($r->body())) ?: [] as $linea) {
                if (preg_match('/^\s*([A-Z]{3})\s+(\d{4})\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*$/', $linea, $m)) {
                    $ultima = ['temporada' => $m[1], 'anio' => (int) $m[2], 'oni' => (float) $m[4]];
                }
            }

            return ['obtenido_en' => now()->toIso8601String(), ...($ultima ?? throw new RuntimeException('NOAA CPC: formato ONI no reconocido.'))];
        });
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function get(string $url, array $query): array
    {
        $r = Http::timeout((int) config('tropilla.clima.timeout_segundos'))->retry(2, 500, throw: false)->acceptJson()->get($url, $query);
        if (! $r->successful()) {
            throw new RuntimeException('Open-Meteo: HTTP '.$r->status().'.');
        }

        return (array) $r->json();
    }

    private static function num(mixed $v): ?float
    {
        return $v === null ? null : round((float) $v, 1);
    }
}
