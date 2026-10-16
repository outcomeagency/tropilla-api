<?php

namespace App\Http\Controllers\Api\V1\Hacienda;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\CorregirEvento;
use App\Domain\Hacienda\Eventos\ResolverRevision;
use App\Domain\Hacienda\Models\Evento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CursorRespuesta;
use App\Http\Resources\V1\Hacienda\EventoResource;
use App\Http\Resources\V1\Hacienda\IntegridadResource;
use App\Http\Resources\V1\Hacienda\RevisionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Correcciones, anulaciones, «ver cambios», integridad y revisiones (TPA-26). */
class CorreccionController extends Controller
{
    public function show(Establecimiento $establecimiento, string $evento): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $e = $this->evento($establecimiento, $evento);
        $versiones = Evento::query()->where('evento_raiz_id', $e->evento_raiz_id)->orderBy('version')->orderBy('registrado_en')->get();
        $vigente = $versiones->where('en_revision', false)->sortByDesc('version')->first();

        return response()->json(['data' => [
            'evento' => EventoResource::make($vigente)->resolve(),
            'versiones' => $versiones->map(fn ($v) => EventoResource::make($v)->resolve())->values()->all(),
            'integridad' => IntegridadResource::deRegistro($vigente->registro_integridad_id),
        ]]);
    }

    public function integridad(Establecimiento $establecimiento, string $evento): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);

        return response()->json(['data' => IntegridadResource::deRegistro($this->evento($establecimiento, $evento)->registro_integridad_id)]);
    }

    public function correccion(Request $request, Establecimiento $establecimiento, string $evento, CorregirEvento $corregir): JsonResponse
    {
        return $this->nuevaVersion($request, $establecimiento, $evento, $corregir, false);
    }

    public function anulacion(Request $request, Establecimiento $establecimiento, string $evento, CorregirEvento $corregir): JsonResponse
    {
        return $this->nuevaVersion($request, $establecimiento, $evento, $corregir, true);
    }

    public function revisiones(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $q = DB::table('revisiones')->where('establecimiento_id', $establecimiento->id)
            ->when($request->query('estado', 'abierta'), fn ($q, $v) => $v === 'todas' ? $q : $q->where('estado', $v))
            ->when($request->query('tipo'), fn ($q, $v) => $q->where('tipo', $v))
            ->orderByDesc('created_at')->orderByDesc('id');

        return CursorRespuesta::make($q->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor'), RevisionResource::class, $request);
    }

    public function revision(Establecimiento $establecimiento, string $revision): RevisionResource
    {
        Gate::authorize('hacienda.ver', $establecimiento);

        return RevisionResource::make($this->revisionDe($establecimiento, $revision));
    }

    public function resolver(Request $request, Establecimiento $establecimiento, string $revision, ResolverRevision $resolver): RevisionResource
    {
        Gate::authorize('revisiones.resolver', $establecimiento);
        $request->validate(['resolucion' => ['required', 'in:reaplicada,descartada,resuelta'], 'motivo' => ['required', 'string', 'min:3', 'max:500'], 'correccion' => ['nullable', 'array']]);

        return RevisionResource::make($resolver->handle($establecimiento, $request->user(), $this->revisionDe($establecimiento, $revision), EventoController::cuerpo($request)));
    }

    private function nuevaVersion(Request $request, Establecimiento $establecimiento, string $evento, CorregirEvento $corregir, bool $anulacion): JsonResponse
    {
        $request->validate([
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'payload' => $anulacion ? ['prohibited'] : ['required', 'array'],
            'id' => ['nullable', 'uuid'],
            'ocurrido_en' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}\.\d{3}Z)?$/'],
            'sujetos' => ['nullable', 'array'],
        ]);
        Gate::authorize('establecimiento.ver', $establecimiento);
        $base = $this->evento($establecimiento, $evento);
        $res = $corregir->handle($establecimiento, $request->user(), $base, EventoController::cuerpo($request), $anulacion);

        return EventoResource::make($res->evento)->response()->setStatusCode($res->estado === 'duplicado' ? 200 : 201);
    }

    private function evento(Establecimiento $est, string $id): Evento
    {
        return Evento::query()->where('id', $id)->where('establecimiento_id', $est->id)->first() ?? throw ApiException::noEncontrado();
    }

    private function revisionDe(Establecimiento $est, string $id): object
    {
        return DB::table('revisiones')->where('id', $id)->where('establecimiento_id', $est->id)->first() ?? throw ApiException::noEncontrado();
    }
}
