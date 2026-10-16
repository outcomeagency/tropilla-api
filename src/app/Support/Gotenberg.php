<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** HTML → PDF con Gotenberg (chromium). Lanza RuntimeException si el servicio no responde. */
final class Gotenberg
{
    public static function html(string $html): string
    {
        $r = Http::timeout(20)->attach('files', $html, 'index.html')->post(rtrim((string) config('services.gotenberg.url'), '/').'/forms/chromium/convert/html');
        if (! $r->successful() || ! str_starts_with($r->body(), '%PDF')) {
            throw new RuntimeException('Gotenberg no generó el PDF (HTTP '.$r->status().').');
        }

        return $r->body();
    }
}
