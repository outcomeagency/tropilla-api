<?php

namespace App\Http\Controllers\Api\V1\Comercial;

use App\Domain\Comercial\Actions\RegistrarOperacion;
use App\Domain\Comercial\ConsultasOperaciones;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Comercial\OperacionRequest;
use App\Http\Requests\V1\MotivoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Operaciones comerciales con trato (TPA-46) — «Mis precios» lee de acá (TPA-47). Privado: dueño/admin. */
class OperacionController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $f = $request->validate(['especie' => ['sometimes', 'exists:especies,codigo'], 'categoria' => ['sometimes', 'string'], 'tipo' => ['sometimes', 'in:venta,compra'],
            'desde' => ['sometimes', 'date_format:Y-m-d'], 'hasta' => ['sometimes', 'date_format:Y-m-d']]);
        $p = DB::table('operaciones')->where('establecimiento_id', $establecimiento->id)
            ->when($f['especie'] ?? null, fn ($q, $v) => $q->where('especie_codigo', $v))
            ->when($f['categoria'] ?? null, fn ($q, $v) => $q->where('categoria_codigo', $v))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('fecha', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('fecha', '<=', $v))
            ->orderByDesc('fecha')->orderByDesc('id')->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return response()->json(['data' => $p->getCollection()->map(fn ($o) => ConsultasOperaciones::operacion($o))->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(OperacionRequest $request, Establecimiento $establecimiento, RegistrarOperacion $registrar): JsonResponse
    {
        $d = $request->validated();
        if (! empty($d['id']) && DB::table('operaciones')->where('id', strtolower($d['id']))->where('establecimiento_id', $establecimiento->id)->exists()) {
            return $this->respuesta(strtolower($d['id']), 200);
        }

        return $this->respuesta($registrar->alta($establecimiento, $request->user(), $d), 201);
    }

    public function show(Establecimiento $establecimiento, string $operacion): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);

        return $this->respuesta($this->buscar($establecimiento, $operacion)->id, 200);
    }

    public function correccion(OperacionRequest $request, Establecimiento $establecimiento, string $operacion, RegistrarOperacion $registrar): JsonResponse
    {
        $op = $this->buscar($establecimiento, $operacion);

        return $this->respuesta($registrar->corregir($establecimiento, $request->user(), $op, $request->validated()), 201);
    }

    public function anulacion(MotivoRequest $request, Establecimiento $establecimiento, string $operacion, RegistrarOperacion $registrar): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $op = $this->buscar($establecimiento, $operacion);

        return $this->respuesta($registrar->anular($establecimiento, $request->user(), $op, $request->validated('motivo')), 201);
    }

    private function respuesta(string $id, int $status): JsonResponse
    {
        return response()->json(['data' => ConsultasOperaciones::operacion(DB::table('operaciones')->where('id', $id)->first())], $status, [], JSON_UNESCAPED_UNICODE);
    }

    private function buscar(Establecimiento $est, string $id): object
    {
        return DB::table('operaciones')->where('establecimiento_id', $est->id)->where('id', strtolower($id))->first() ?? throw ApiException::noEncontrado();
    }
}
