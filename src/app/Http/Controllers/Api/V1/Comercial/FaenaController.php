<?php

namespace App\Http\Controllers\Api\V1\Comercial;

use App\Domain\Comercial\Faena\Actions\ActualizarResultadoFaena;
use App\Domain\Comercial\Faena\Actions\RegistrarResultadoFaena;
use App\Domain\Comercial\Faena\ExportarConciliacion;
use App\Domain\Comercial\Faena\PlanillaFaena;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Comercial\ResultadoFaenaRequest;
use App\Http\Resources\V1\Comercial\ResultadoFaenaResource;
use App\Http\Resources\V1\CursorOffset;
use App\Rules\Reglas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resultado de faena y conciliación de venta (TPA-67). Privado: `comercial.precios` (dueño/admin), como las operaciones.
 * Nada viaja por sync ni a lo público.
 */
class FaenaController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $request->validate(['operacion_id' => ['sometimes', Reglas::UUID], 'borrador_id' => ['sometimes', Reglas::UUID], 'estado' => ['sometimes', 'in:vigente,anulado,todos']]);
        $estado = $request->query('estado', 'vigente');
        $q = DB::table('resultados_faena')->where('establecimiento_id', $establecimiento->id)
            ->when($request->query('operacion_id'), fn ($q, $o) => $q->where('operacion_id', $o))
            ->when($request->query('borrador_id'), fn ($q, $b) => $q->where('borrador_id', $b))
            ->when($estado !== 'todos', fn ($q) => $q->where('estado', $estado))
            ->orderByDesc('fecha_faena')->orderByDesc('created_at')->orderByDesc('id');

        return CursorOffset::responder($q, $request, $request->only(['operacion_id', 'borrador_id', 'estado']), fn ($r) => ResultadoFaenaResource::make($r));
    }

    public function store(ResultadoFaenaRequest $request, Establecimiento $establecimiento, RegistrarResultadoFaena $registrar): JsonResponse
    {
        [$r, $creado] = $registrar->handle($establecimiento, $request->user(), $request->validated(), $request->boolean('confirmar_advertencias'));

        return response()->json(['data' => ResultadoFaenaResource::make($r, true)], $creado ? 201 : 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Establecimiento $establecimiento, string $resultado): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);

        return response()->json(['data' => ResultadoFaenaResource::make($this->buscar($establecimiento, $resultado), true)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(ResultadoFaenaRequest $request, Establecimiento $establecimiento, string $resultado, ActualizarResultadoFaena $actualizar): JsonResponse
    {
        $r = $actualizar->handle($establecimiento, $request->user(), $this->buscar($establecimiento, $resultado), $request->validated());

        return response()->json(['data' => ResultadoFaenaResource::make($r, true)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function anular(Request $request, Establecimiento $establecimiento, string $resultado, ActualizarResultadoFaena $actualizar): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);
        $r = $actualizar->anular($establecimiento, $request->user(), $this->buscar($establecimiento, $resultado), $d['motivo']);

        return response()->json(['data' => ResultadoFaenaResource::make($r)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Lee la planilla digital del frigorífico y devuelve las reses para revisar (no guarda nada). */
    public function leerPlanilla(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $request->validate(['archivo' => ['required', 'file', 'max:5120']]);
        $archivo = $request->file('archivo');
        $ext = strtolower((string) $archivo->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            throw ApiException::validacion(__('Subí la planilla en CSV o Excel (.xlsx).'), ['archivo' => [__('Formato no admitido.')]]);
        }
        try {
            $r = PlanillaFaena::leer((string) $archivo->get(), $ext === 'xlsx' ? 'xlsx' : 'csv');
        } catch (\Throwable) {
            throw ApiException::validacion(__('No se pudo leer la planilla.'), ['archivo' => [__('Archivo dañado o con otro formato.')]]);
        }
        if ($r['reses'] === []) {
            throw ApiException::validacion(__('La planilla no tiene filas de reses.'), ['archivo' => [__('Vacía.')]]);
        }

        return response()->json(['data' => $r], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function exportar(Request $request, Establecimiento $establecimiento, string $resultado): Response
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $d = $request->validate(['formato' => ['sometimes', 'in:pdf,csv']]);
        $r = $this->buscar($establecimiento, $resultado);
        $nombre = 'conciliacion-faena-'.($r->tropa_numero ? preg_replace('/[^A-Za-z0-9_-]/', '', $r->tropa_numero) : $r->fecha_faena);
        if (($d['formato'] ?? 'pdf') === 'csv') {
            return response(ExportarConciliacion::csv($r), 200, ['Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$nombre}.csv\"", 'Cache-Control' => 'private, no-store']);
        }
        try {
            $pdf = ExportarConciliacion::pdf($establecimiento, $r);
        } catch (RuntimeException) {
            throw new ApiException(503, 'servicio_no_disponible', __('No se pudo generar el PDF ahora. Probá de nuevo o bajá el CSV.'));
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$nombre}.pdf\"", 'Cache-Control' => 'private, no-store']);
    }

    private function buscar(Establecimiento $est, string $id): object
    {
        $id = strtolower($id);
        $r = preg_match('/^[0-9a-f-]{36}$/', $id) ? DB::table('resultados_faena')->where('id', $id)->where('establecimiento_id', $est->id)->first() : null;

        return $r ?? throw ApiException::noEncontrado();
    }
}
