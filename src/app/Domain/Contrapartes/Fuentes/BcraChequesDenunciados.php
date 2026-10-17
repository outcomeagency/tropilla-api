<?php

namespace App\Domain\Contrapartes\Fuentes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BcraChequesDenunciados
{
    public function entidades(): array
    {
        return Cache::remember('contrapartes:bcra:entidades', now()->addHours(24), function () {
            $r = Http::acceptJson()->connectTimeout(5)->timeout(10)->get(rtrim(config('tropilla.contrapartes.cheques_url'), '/').'/entidades');
            if (! $r->successful() || ! is_array($r->json('results'))) {
                throw new RuntimeException('La fuente de entidades del BCRA no respondió.');
            }

            return $r->json('results');
        });
    }

    public function consultar(int $banco, int $numero): array
    {
        $key = "contrapartes:bcra:cheque:{$banco}:{$numero}";

        return Cache::remember($key, now()->addHours(6), function () use ($banco, $numero) {
            $r = Http::acceptJson()->connectTimeout(5)->timeout((int) config('tropilla.contrapartes.timeout_segundos'))
                ->get(rtrim(config('tropilla.contrapartes.cheques_url'), '/')."/denunciados/{$banco}/{$numero}");
            if (! $r->successful() || ! is_array($r->json('results'))) {
                throw new RuntimeException('La fuente de cheques denunciados del BCRA no respondió.');
            }

            return ['estado' => 'ok', 'consultado_en' => now()->toIso8601String(), 'fuente' => 'BCRA — Cheques denunciados', 'url' => 'https://www.bcra.gob.ar/cheques-denunciados/', 'datos' => $r->json('results')];
        });
    }
}
