<?php

namespace App\Http\Controllers\Api\V1\Catalogos;

use App\Domain\Catalogos\Catalogos;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /catalogos (público, cacheable) y GET /sync/catalogos (app). Mismo paquete.
 * 304 si el cliente ya tiene la versión vigente: `?version=<n>` (entero, tolera espacios/«v»)
 * o `If-None-Match` con el ETag (comparación débil: `W/"…"` y `*` también valen, RFC 9110 §13.1.2).
 * /sync/catalogos va con token → `Cache-Control: private` (nunca en caché compartida).
 */
class CatalogoController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $locale = app()->getLocale();
        $version = Catalogos::version();
        $etag = '"catalogos-v'.$version.'-'.$locale.'"';
        $cache = $request->routeIs('v1.sync.catalogos')
            ? 'private, no-cache'
            : 'public, max-age=60, stale-while-revalidate=600';
        $headers = ['ETag' => $etag, 'Cache-Control' => $cache, 'Vary' => 'Accept-Language'];

        if ($this->versionPedida($request) === $version || $this->etagCoincide($request, $etag)) {
            return response()->noContent(304)->withHeaders($headers);
        }

        return response()->json(['data' => Catalogos::paquete($locale)], 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** `?version=` como entero no negativo; cualquier otra cosa (vacío, basura) = no tiene versión → 200. */
    private function versionPedida(Request $request): ?int
    {
        $v = $request->query('version');
        if (! is_scalar($v)) {
            return null;
        }
        $v = ltrim(trim((string) $v), 'vV');

        return preg_match('/^\d{1,9}$/', $v) ? (int) $v : null;
    }

    private function etagCoincide(Request $request, string $etag): bool
    {
        foreach ($request->getETags() as $candidato) {
            $candidato = trim($candidato);
            if ($candidato === '*' || preg_replace('#^W/#', '', $candidato) === $etag) {
                return true;
            }
        }

        return false;
    }
}
