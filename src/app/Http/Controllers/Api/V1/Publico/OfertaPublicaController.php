<?php

namespace App\Http\Controllers\Api\V1\Publico;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Publico\OfertaPublicaResource;
use App\Support\Leyenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/** Ficha pública de la oferta (`/o/{codigo}`), sin cuenta. Cacheable. */
class OfertaPublicaController extends Controller
{
    public function show(Request $request, string $codigo): JsonResponse
    {
        [$o, $pub] = $this->buscar($codigo);
        $locale = Leyenda::locale($request->query('locale'));

        return response()->json(['data' => OfertaPublicaResource::armar($o, json_decode($pub->contenido, true), $pub->hash, (int) $pub->version, $locale, $pub->created_at, (int) $pub->registro_integridad_id)], 200,
            ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=600', 'ETag' => '"of-'.$pub->hash.'-'.$o->estado.'-'.$locale.'"']);
    }

    /**
     * PDF de la ficha (Gotenberg). Desvío: se devuelve el PDF directo (200 application/pdf) en vez de un 302 a una
     * URL prefirmada, hasta tener la subida a S3 de documentos generados (TPA-36/44).
     */
    public function pdf(Request $request, string $codigo): Response
    {
        [$o, $pub] = $this->buscar($codigo);
        $locale = Leyenda::locale($request->query('locale'));
        $d = OfertaPublicaResource::armar($o, json_decode($pub->contenido, true), $pub->hash, (int) $pub->version, $locale, $pub->created_at, (int) $pub->registro_integridad_id);
        $html = view('pdf.oferta', ['o' => $d])->render();
        try {
            $r = Http::timeout(20)->attach('files', $html, 'index.html')->post(rtrim((string) config('services.gotenberg.url'), '/').'/forms/chromium/convert/html');
        } catch (\Throwable $e) {
            report($e);
            throw new ApiException(503, 'pdf_no_disponible', __('No se pudo generar el PDF. Probá en unos minutos.'));
        }
        if (! $r->successful()) {
            throw new ApiException(503, 'pdf_no_disponible', __('No se pudo generar el PDF. Probá en unos minutos.'));
        }

        return response($r->body(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"oferta-{$codigo}.pdf\""]);
    }

    /** @return array{0: object, 1: object} */
    private function buscar(string $codigo): array
    {
        $o = DB::table('ofertas')->where('codigo_publico', strtoupper($codigo))->whereIn('estado', ['publicada', 'reservada', 'vendida'])->first();
        $pub = $o ? DB::table('oferta_publicaciones')->where('oferta_id', $o->id)->orderByDesc('version')->first() : null;
        if ($pub === null) {
            throw ApiException::noEncontrado();
        }

        return [$o, $pub];
    }
}
