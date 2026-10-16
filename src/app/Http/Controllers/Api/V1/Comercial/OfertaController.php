<?php

namespace App\Http\Controllers\Api\V1\Comercial;

use App\Domain\Comercial\Actions\GestionarOferta;
use App\Domain\Comercial\Models\Oferta;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Comercial\OfertaResource;
use App\Http\Resources\V1\CursorRespuesta;
use App\Http\Resources\V1\Publico\OfertaPublicaResource;
use App\Rules\Reglas;
use App\Support\Leyenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Ofertas del establecimiento (TPA-48). */
class OfertaController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('ofertas.ver', $establecimiento);
        $q = Oferta::query()->where('establecimiento_id', $establecimiento->id)
            ->when($request->query('estado'), fn ($q, $e) => $q->where('estado', $e))
            ->orderByDesc('created_at')->orderByDesc('id');

        return CursorRespuesta::make($q->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor'), OfertaResource::class, $request);
    }

    public function store(Request $request, Establecimiento $establecimiento, GestionarOferta $gestionar): JsonResponse
    {
        Gate::authorize('ofertas.gestionar', $establecimiento);

        return OfertaResource::make($gestionar->crear($establecimiento, $request->user(), $this->validar($request, false)))->response()->setStatusCode(201);
    }

    /** Borrador precargado desde un lote o una selección de animales. */
    public function desdeLote(Request $request, Establecimiento $establecimiento, GestionarOferta $gestionar): JsonResponse
    {
        Gate::authorize('ofertas.gestionar', $establecimiento);
        $d = $request->validate([
            'tipo' => ['required', 'in:invernada,gordo,cria,reproductores'],
            'lote_id' => ['required_without:animal_ids', 'nullable', 'uuid'],
            'animal_ids' => ['required_without:lote_id', 'array', 'max:1000'],
            'animal_ids.*' => ['uuid'],
        ]);

        return OfertaResource::make($gestionar->crear($establecimiento, $request->user(), $d))->response()->setStatusCode(201);
    }

    public function show(Establecimiento $establecimiento, string $oferta): OfertaResource
    {
        Gate::authorize('ofertas.ver', $establecimiento);

        return OfertaResource::make($this->oferta($establecimiento, $oferta));
    }

    public function update(Request $request, Establecimiento $establecimiento, string $oferta, GestionarOferta $gestionar): OfertaResource
    {
        Gate::authorize('ofertas.gestionar', $establecimiento);

        return OfertaResource::make($gestionar->editar($this->oferta($establecimiento, $oferta), $request->user(), $this->validar($request, true)));
    }

    public function avisos(Establecimiento $establecimiento, string $oferta): OfertaResource
    {
        Gate::authorize('ofertas.gestionar', $establecimiento);

        return OfertaResource::make($this->oferta($establecimiento, $oferta));
    }

    /** Vista «como la ve el comprador» con los datos actuales (sin publicar). */
    public function preview(Request $request, Establecimiento $establecimiento, string $oferta, GestionarOferta $gestionar): JsonResponse
    {
        Gate::authorize('ofertas.gestionar', $establecimiento);
        $o = $this->oferta($establecimiento, $oferta);
        $contenido = $gestionar->contenidoPublico($o);

        return response()->json(['data' => OfertaPublicaResource::armar($o, $contenido, str_repeat('0', 64), ($o->publicacion_vigente ?? 0) + 1, Leyenda::locale($request->query('locale')))]);
    }

    public function publicar(Request $request, Establecimiento $establecimiento, string $oferta, GestionarOferta $gestionar): OfertaResource
    {
        Gate::authorize('ofertas.publicar', $establecimiento);
        $request->validate(['confirmar_avisos' => ['nullable', 'boolean']]);

        return OfertaResource::make($gestionar->publicar($this->oferta($establecimiento, $oferta), $request->user(), $request->boolean('confirmar_avisos')));
    }

    public function estado(Request $request, Establecimiento $establecimiento, string $oferta, GestionarOferta $gestionar): OfertaResource
    {
        Gate::authorize('ofertas.publicar', $establecimiento);
        $d = $request->validate(['estado' => ['required', 'in:reservada,vendida,retirada,publicada'], 'motivo' => ['nullable', 'string', 'max:500']]);

        return OfertaResource::make($gestionar->cambiarEstado($this->oferta($establecimiento, $oferta), $request->user(), $d['estado'], $d['motivo'] ?? null));
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, bool $patch): array
    {
        return $request->validate([
            'row_version' => $patch ? ['required', 'integer'] : ['prohibited'],
            'tipo' => [$patch ? 'sometimes' : 'required', 'in:invernada,gordo,cria,reproductores'],
            'especie' => ['sometimes', 'nullable', 'exists:especies,codigo'],
            'lote_id' => ['sometimes', 'nullable', 'uuid'],
            'animal_ids' => ['sometimes', 'array', 'max:1000'],
            'animal_ids.*' => ['uuid'],
            'cantidad' => ['sometimes', 'integer', 'min:1'],
            'categorias' => ['sometimes', 'array'], 'categorias.*' => ['string', 'exists:categorias,codigo'],
            'razas' => ['sometimes', 'array'], 'razas.*' => ['string'],
            'mostrar' => ['sometimes', 'array'],
            'condiciones' => ['sometimes', 'array'],
            'condiciones.modalidad' => ['sometimes', 'in:en_pie,al_gancho'],
            'condiciones.mostrar_precio' => ['sometimes', 'boolean'],
            'condiciones.precio_kg' => ['sometimes', 'nullable', 'string', Reglas::DECIMAL_POS],
            'condiciones.moneda' => ['sometimes', 'in:ARS,USD'],
            'condiciones.lugar_carga' => ['sometimes', 'nullable', 'string', 'max:120'],
            'condiciones.disponible_desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'condiciones.disponible_hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'textos' => ['sometimes', 'array'],
            'textos.*.titulo' => ['sometimes', 'string', 'max:120'],
            'textos.*.descripcion' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'fotos' => ['sometimes', 'array', 'max:12'], 'fotos.*' => [Reglas::HASH, Reglas::FOTO],
        ]);
    }

    private function oferta(Establecimiento $est, string $id): Oferta
    {
        return Oferta::query()->where('id', $id)->where('establecimiento_id', $est->id)->first() ?? throw ApiException::noEncontrado();
    }
}
