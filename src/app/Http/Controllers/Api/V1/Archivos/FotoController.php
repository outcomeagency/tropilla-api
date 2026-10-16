<?php

namespace App\Http\Controllers\Api\V1\Archivos;

use App\Domain\Documentos\Fotos;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Sirve fotos (bugfix TPA-54): públicas por hash (inmutables) o privadas con URL firmada. Cualquier otro caso → 404. */
class FotoController extends Controller
{
    public function publica(Request $request, string $sha256): Response
    {
        $sha = strtolower($sha256);
        if (! Fotos::esPublica($sha)) {
            throw ApiException::noEncontrado();
        }

        return $this->servir($request, $sha, 'public, max-age=31536000, immutable');
    }

    public function firmada(Request $request, string $sha256): Response
    {
        if (! $request->hasValidRelativeSignature()) {
            throw ApiException::noEncontrado(); // sin firma (o vencida) no se filtra existencia
        }
        $restante = max(0, (int) $request->query('expires') - now()->getTimestamp());

        return $this->servir($request, strtolower($sha256), "private, max-age={$restante}");
    }

    private function servir(Request $request, string $sha, string $cache): Response
    {
        $a = DB::table('archivos')->where('sha256', $sha)->where('estado', 'completo')->first();
        if ($a === null || ! str_starts_with((string) $a->mime, 'image/')) {
            throw ApiException::noEncontrado();
        }
        $headers = ['Content-Type' => $a->mime, 'Cache-Control' => $cache, 'ETag' => '"'.$sha.'"', 'X-Content-Type-Options' => 'nosniff'];
        if (trim((string) $request->header('If-None-Match'), '"W/ ') === $sha) {
            return response('', 304, $headers);
        }
        $bytes = Storage::exists($a->s3_clave) ? Storage::get($a->s3_clave) : null;
        if ($bytes === null) {
            throw ApiException::noEncontrado();
        }

        return response($bytes, 200, [...$headers, 'Content-Length' => (string) strlen($bytes)]);
    }
}
