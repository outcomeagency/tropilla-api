<?php

namespace App\Http\Controllers\Api\V1\Catalogos;

use App\Domain\Catalogos\Catalogos;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /catalogos (público, cacheable) y GET /sync/catalogos (app). Mismo paquete.
 * 304 si el cliente ya tiene la versión (`?version=` o `If-None-Match`).
 */
class CatalogoController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $locale = app()->getLocale();
        $version = Catalogos::version();
        $etag = '"catalogos-v'.$version.'-'.$locale.'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=60, stale-while-revalidate=600', 'Vary' => 'Accept-Language'];

        $alDia = ($request->query('version') !== null && (int) $request->query('version') === $version)
            || in_array($etag, $request->getETags(), true);
        if ($alDia) {
            return response()->noContent(304)->withHeaders($headers);
        }

        return response()->json(['data' => Catalogos::paquete($locale)], 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
