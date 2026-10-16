<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * request_id de api-v1.md §3: se toma de X-Request-Id si viene (y es razonable)
 * o se genera; vuelve en el header y en el cuerpo de los errores.
 * También fija el idioma de los mensajes desde Accept-Language / ?locale (es|en|pt).
 */
class AsignarRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $entrante = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $entrante) ? $entrante : (string) Str::uuid7();
        $request->attributes->set('request_id', $id);

        $locale = $request->query('locale') ?: $request->getPreferredLanguage(['es', 'en', 'pt']);
        app()->setLocale(in_array($locale, ['es', 'en', 'pt'], true) ? $locale : 'es');

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
