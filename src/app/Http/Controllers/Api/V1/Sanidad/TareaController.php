<?php

namespace App\Http\Controllers\Api\V1\Sanidad;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sanidad\Actions\GestionarTarea;
use App\Domain\Sanidad\Models\Tarea;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\MotivoRequest;
use App\Http\Requests\V1\Sanidad\TareaRequest;
use App\Http\Resources\V1\CursorRespuesta;
use App\Http\Resources\V1\Sanidad\TareaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/** Calendario de tareas sanitarias (TPA-33). */
class TareaController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('sanidad.ver', $establecimiento);
        $f = $request->validate([
            'estado' => ['sometimes', 'in:pendiente,vencida,hecha,omitida,abiertas'],
            'desde' => ['sometimes', 'date_format:Y-m-d'], 'hasta' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $hoy = Carbon::now($establecimiento->zona_horaria)->toDateString();

        $q = Tarea::query()->where('establecimiento_id', $establecimiento->id)
            ->when(($f['estado'] ?? null) === 'pendiente', fn ($q) => $q->where('estado', 'pendiente')->where('vence_en', '>=', $hoy))
            ->when(($f['estado'] ?? null) === 'vencida', fn ($q) => $q->where('estado', 'pendiente')->where('vence_en', '<', $hoy))
            ->when(($f['estado'] ?? null) === 'abiertas', fn ($q) => $q->where('estado', 'pendiente'))
            ->when(in_array($f['estado'] ?? null, ['hecha', 'omitida'], true), fn ($q) => $q->where('estado', $f['estado']))
            ->when($f['desde'] ?? null, fn ($q, $d) => $q->where('vence_en', '>=', $d))
            ->when($f['hasta'] ?? null, fn ($q, $h) => $q->where('vence_en', '<=', $h))
            ->orderBy('vence_en')->orderBy('id');
        $pagina = $q->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor');
        $pagina->getCollection()->each(fn ($t) => $t->setAttribute('zona_horaria', $establecimiento->zona_horaria));

        return CursorRespuesta::make($pagina, TareaResource::class, $request);
    }

    public function store(TareaRequest $request, Establecimiento $establecimiento, GestionarTarea $gestionar): JsonResponse
    {
        return $this->res($establecimiento, $gestionar->crear($establecimiento, $request->user(), $request->validated()))->response()->setStatusCode(201);
    }

    public function update(TareaRequest $request, Establecimiento $establecimiento, string $tarea, GestionarTarea $gestionar): TareaResource
    {
        return $this->res($establecimiento, $gestionar->reprogramar($establecimiento, $this->buscar($establecimiento, $tarea), $request->user(), $request->validated()));
    }

    public function omitir(MotivoRequest $request, Establecimiento $establecimiento, string $tarea, GestionarTarea $gestionar): TareaResource
    {
        Gate::authorize('tareas.gestionar', $establecimiento);

        return $this->res($establecimiento, $gestionar->omitir($establecimiento, $this->buscar($establecimiento, $tarea), $request->user(), $request->validated('motivo')));
    }

    private function res(Establecimiento $est, Tarea $t): TareaResource
    {
        return TareaResource::make($t->setAttribute('zona_horaria', $est->zona_horaria));
    }

    private function buscar(Establecimiento $est, string $id): Tarea
    {
        return Tarea::query()->where('establecimiento_id', $est->id)->where('id', strtolower($id))->first() ?? throw ApiException::noEncontrado();
    }
}
