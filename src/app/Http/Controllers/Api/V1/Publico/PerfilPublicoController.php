<?php

namespace App\Http\Controllers\Api\V1\Publico;

use App\Domain\Contratos\ConsultasContratos;
use App\Domain\Perfil\Services\PerfilPublicoBuilder;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Publico\OfertaPublicaResource;
use App\Http\Resources\V1\Publico\PerfilBloqueResource;
use App\Support\Leyenda;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** `/p/{slug}` sin cuenta (TPA-50): perfil publicado; slug viejo → 301 al vigente. */
class PerfilPublicoController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $p = $this->perfil($slug);
        $locale = Leyenda::locale($request->query('locale'));

        return response()->json(['data' => PerfilPublicoBuilder::armar($p, $locale)], 200, ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=600']);
    }

    public function ofertas(Request $request, string $slug): JsonResponse
    {
        $p = $this->perfil($slug);
        $locale = Leyenda::locale($request->query('locale'));

        return response()->json(['data' => DB::table('ofertas')->where('establecimiento_id', $p->establecimiento_id)->whereIn('estado', ['publicada', 'reservada'])
            ->orderByDesc('publicada_en')->get()->map(fn ($o) => OfertaPublicaResource::resumen($o, $locale))->filter()->values()->all()]);
    }

    /** Contratos a futuro abiertos del perfil (TPA-56). Precios solo para compradores con cuenta. */
    public function contratos(Request $request, string $slug): JsonResponse
    {
        $p = $this->perfil($slug);
        $locale = Leyenda::locale($request->query('locale'));
        $comprador = ContratoPublicoController::esComprador();

        return response()->json(['data' => DB::table('contratos_futuro')->where('establecimiento_id', $p->establecimiento_id)->whereIn('estado', ContratoPublicoController::VISIBLES)
            ->orderBy('fecha_entrega')->get()->map(fn ($c) => ConsultasContratos::publico($c, $comprador, $locale))->all()], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function bloque(Request $request, string $slug, string $bloque): PerfilBloqueResource
    {
        abort_unless(in_array($bloque, ['reproductores', 'composicion', 'indicadores', 'galeria'], true), 404);
        $perfil = $this->perfil($slug);
        $datos = PerfilPublicoBuilder::armar($perfil, Leyenda::locale($request->query('locale')));

        return new PerfilBloqueResource($datos['secciones'][$bloque] ?? null);
    }

    private function perfil(string $slug): object
    {
        $p = DB::table('perfiles_publicos')->where('slug', $slug)->where('publicado', true)->first();
        if ($p === null) {
            $hist = DB::table('perfil_slugs_historicos')->where('slug', $slug)->first();
            $nuevo = $hist ? DB::table('perfiles_publicos')->where('establecimiento_id', $hist->establecimiento_id)->where('publicado', true)->value('slug') : null;
            if ($nuevo) {
                throw new HttpResponseException(
                    response()->json(['data' => ['slug' => $nuevo]], 301, ['Location' => rtrim((string) config('app.url'), '/')."/api/v1/public/establecimientos/{$nuevo}"]));
            }
            throw ApiException::noEncontrado();
        }

        return $p;
    }
}
