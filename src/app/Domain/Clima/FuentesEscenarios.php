<?php

namespace App\Domain\Clima;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Adaptadores públicos con caché: los datos crudos conservan fuente y fecha de consulta. */
final class FuentesEscenarios
{
    public function observaciones(float $lat, float $lon): array
    {
        return Cache::remember("escenarios:nasa-power:$lat:$lon", now()->addHours(24), function () use ($lat, $lon) {
            $hasta = now()->subDays(3)->format('Ymd');
            $desde = now()->subMonths(6)->format('Ymd');
            $url = 'https://power.larc.nasa.gov/api/temporal/daily/point';
            $r = Http::timeout(20)->retry(2, 500, throw: false)->get($url, [
                'parameters' => 'PRECTOTCORR,T2M,T2M_MAX,T2M_MIN,RH2M', 'community' => 'AG', 'longitude' => $lon,
                'latitude' => $lat, 'start' => $desde, 'end' => $hasta, 'format' => 'JSON', 'time-standard' => 'UTC',
            ]);
            if (! $r->successful()) {
                throw new RuntimeException('NASA POWER no respondió correctamente.');
            }
            $body = (array) $r->json();

            return ['fuente' => 'NASA POWER', 'url' => $url, 'licencia' => 'Datos públicos NASA; atribución NASA POWER', 'consultado_en' => now()->toIso8601String(),
                'serie' => $body['properties']['parameter'] ?? [], 'unidad' => $body['parameters'] ?? []];
        });
    }

    public function ndvi(float $lat, float $lon): array
    {
        return Cache::remember("escenarios:ornl-ndvi:$lat:$lon", now()->addDays(8), function () use ($lat, $lon) {
            $base = 'https://modis.ornl.gov/rst/api/v1/MOD13Q1';
            $dates = Http::timeout(20)->retry(1, 500, throw: false)->get("$base/dates", ['latitude' => $lat, 'longitude' => $lon]);
            if (! $dates->successful()) {
                throw new RuntimeException('ORNL MODIS no publicó fechas disponibles.');
            }
            $available = array_column((array) $dates->json('dates', []), 'modis_date');
            $desde = 'A'.now()->subMonths(6)->format('Yz');
            $hasta = 'A'.now()->format('Yz');
            $available = array_slice(array_values(array_filter($available, fn ($date) => is_string($date) && $date >= $desde && $date <= $hasta)), -10);
            if ($available === []) {
                throw new RuntimeException('ORNL MODIS no tiene composiciones recientes para esta coordenada.');
            }
            $r = Http::timeout(20)->retry(1, 500, throw: false)->get("$base/subset", ['latitude' => $lat, 'longitude' => $lon, 'startDate' => $available[0], 'endDate' => end($available), 'kmAboveBelow' => 0, 'kmLeftRight' => 0]);
            if (! $r->successful()) {
                throw new RuntimeException('ORNL MODIS no respondió; NDVI queda sin dato.');
            }
            $body = (array) $r->json();

            return ['fuente' => 'NASA MODIS vía ORNL DAAC', 'url' => 'https://modis.ornl.gov/rst/ui/', 'licencia' => 'Datos NASA Earth science: uso y redistribución con atribución; revisar términos del producto',
                'consultado_en' => now()->toIso8601String(), 'producto' => 'MOD13Q1', 'respuesta' => $body];
        });
    }

    public function enso(): array
    {
        return Cache::remember('escenarios:noaa-cpc-enso', now()->addDays(14), function () {
            $probabilidades = 'https://www.cpc.ncep.noaa.gov/products/analysis_monitoring/enso/roni/probabilities/';
            $r = Http::timeout(20)->retry(1, 500, throw: false)->get($probabilidades);
            if (! $r->successful()) {
                throw new RuntimeException('NOAA CPC no respondió.');
            }
            $html = (string) $r->body();
            $doc = new \DOMDocument;
            libxml_use_internal_errors(true);
            $doc->loadHTML($html);
            libxml_clear_errors();
            $periodos = [];
            foreach ((new \DOMXPath($doc))->query('//table//tr') ?: [] as $row) {
                $celdas = array_map(fn ($cell) => trim($cell->textContent), iterator_to_array($row->childNodes));
                $celdas = array_values(array_filter($celdas, fn ($cell) => $cell !== ''));
                if (count($celdas) < 6 || ! preg_match('/^[A-Z]{3}$/', $celdas[0])) {
                    continue;
                }
                $numeros = array_values(array_filter($celdas, fn ($cell) => preg_match('/^\d{1,3}$/', $cell)));
                if (count($numeros) >= 3) {
                    $ultimos = array_slice($numeros, -3);
                    $periodos[] = ['temporada' => $celdas[0], 'probabilidades' => ['la_nina_pct' => (int) $ultimos[0], 'neutral_pct' => (int) $ultimos[1], 'el_nino_pct' => (int) $ultimos[2]]];
                }
            }

            return ['fuente' => 'NOAA CPC — ENSO Probabilities', 'url' => $probabilidades, 'licencia' => 'Información del gobierno de EE. UU., dominio público',
                'consultado_en' => now()->toIso8601String(), 'periodos' => array_slice($periodos, 0, 9),
                'nota' => 'Probabilidades oficiales para fase ENSO en el Pacífico ecuatorial; no son probabilidades de lluvia o impacto para un establecimiento argentino.'];
        });
    }
}
