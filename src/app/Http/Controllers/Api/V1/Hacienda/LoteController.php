<?php

namespace App\Http\Controllers\Api\V1\Hacienda;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Actions\GuardarLote;
use App\Domain\Hacienda\Eventos\ConsultasEventos;
use App\Domain\Hacienda\Models\Lote;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hacienda\LoteRequest;
use App\Http\Requests\V1\MotivoRequest;
use App\Http\Resources\V1\CursorRespuesta;
use App\Http\Resources\V1\Hacienda\EventoResumenResource;
use App\Http\Resources\V1\Hacienda\LoteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Lotes de manejo y anónimos (TPA-20). Composición y potrero salen de eventos. */
class LoteController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): AnonymousResourceCollection
    {
        Gate::authorize('establecimiento.ver', $establecimiento);

        return LoteResource::collection(Lote::query()->where('establecimiento_id', $establecimiento->id)
            ->when($request->query('especie'), fn ($q, $e) => $q->where('especie_codigo', $e))
            ->when(! $request->boolean('archivados'), fn ($q) => $q->whereNull('archivado_en'))
            ->orderBy('especie_codigo')->orderByRaw('lower(nombre)')->get());
    }

    public function store(LoteRequest $request, Establecimiento $establecimiento, GuardarLote $guardar): JsonResponse
    {
        Gate::authorize('maestros.editar', $establecimiento);
        ['lote' => $l, 'creado' => $creado] = $guardar->crear($establecimiento, $request->user(), $request->validated());

        return LoteResource::make($l)->response()->setStatusCode($creado ? 201 : 200);
    }

    public function show(Establecimiento $establecimiento, string $lote): LoteResource
    {
        Gate::authorize('establecimiento.ver', $establecimiento);

        return LoteResource::make($this->lote($establecimiento, $lote));
    }

    public function update(LoteRequest $request, Establecimiento $establecimiento, string $lote, GuardarLote $guardar): JsonResponse
    {
        Gate::authorize('maestros.editar', $establecimiento);

        return LoteResource::make($guardar->editar($this->lote($establecimiento, $lote), $request->user(), $request->validated()))->response()->setStatusCode(200);
    }

    public function archivar(MotivoRequest $request, Establecimiento $establecimiento, string $lote, GuardarLote $guardar): JsonResponse
    {
        Gate::authorize('maestros.editar', $establecimiento);

        return LoteResource::make($guardar->archivar($this->lote($establecimiento, $lote), $request->user(), $request->validated('motivo')))->response()->setStatusCode(200);
    }

    public function eventos(Request $request, Establecimiento $establecimiento, string $lote): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $this->lote($establecimiento, $lote);
        $q = ConsultasEventos::timelineLote($establecimiento->id, $lote, $request->query('tipo'));

        return CursorRespuesta::make($q->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor'), EventoResumenResource::class, $request);
    }

    private function lote(Establecimiento $est, string $id): Lote
    {
        return Lote::query()->where('id', $id)->where('establecimiento_id', $est->id)->first() ?? throw ApiException::noEncontrado();
    }
}
