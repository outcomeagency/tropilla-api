<?php

namespace App\Http\Controllers\Api\V1\Publico;

use App\Domain\Documentos\Actions\GestionarEnlace;
use App\Http\Controllers\Controller;
use App\Support\Leyenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /public/enlaces/{token} (TPA-38): documento compartido, sin cuenta; 410 si venció o fue revocado. Nunca cacheable. */
class EnlacePublicoController extends Controller
{
    public function __invoke(Request $request, string $token, GestionarEnlace $gestionar): JsonResponse
    {
        $locale = Leyenda::locale($request->query('locale') ?: app()->getLocale());

        return response()->json(['data' => $gestionar->abrir($token, $request->ip(), $request->userAgent(), $locale)], 200,
            ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
