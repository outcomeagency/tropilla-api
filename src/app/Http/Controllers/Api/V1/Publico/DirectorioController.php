<?php

namespace App\Http\Controllers\Api\V1\Publico;

use App\Domain\Perfil\Services\Directorio;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CursorOffset;
use App\Support\Leyenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Directorio público y sitemap (TPA-54). Sin cuenta, cacheable. */
class DirectorioController extends Controller
{
    private const FILTROS = ['q', 'provincia', 'departamento', 'especie', 'raza', 'categoria', 'con_oferta', 'con_contrato'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->only(self::FILTROS);
        $locale = Leyenda::locale($request->query('locale'));
        $q = Directorio::consulta($f)->orderByDesc('ultima_actividad')->orderByDesc('p.completitud')->orderBy('p.establecimiento_id');

        return CursorOffset::responder($q, $request, $f, fn ($r) => Directorio::item($r, $locale))
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
    }

    public function facetas(Request $request): JsonResponse
    {
        return response()->json(['data' => Directorio::facetas($request->only(self::FILTROS), Leyenda::locale($request->query('locale')))])
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
    }

    public function sitemap(): JsonResponse
    {
        return response()->json(['data' => Directorio::sitemap()])->header('Cache-Control', 'public, max-age=600');
    }
}
