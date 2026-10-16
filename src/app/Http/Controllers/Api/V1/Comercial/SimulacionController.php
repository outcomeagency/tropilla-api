<?php

namespace App\Http\Controllers\Api\V1\Comercial;

use App\Domain\Comercial\SimuladorLiquidacion;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Comercial\SimulacionRequest;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Simulador en pie vs al gancho (TPA-45): calcular sin guardar, guardar (inmutable) y listar. Privado: dueño/admin. */
class SimulacionController extends Controller
{
    public function calcular(SimulacionRequest $request, Establecimiento $establecimiento, SimuladorLiquidacion $simulador): JsonResponse
    {
        return response()->json(['data' => $simulador->calcular($request->validated('entradas'), $establecimiento->id)['resultado']], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(SimulacionRequest $request, Establecimiento $establecimiento, SimuladorLiquidacion $simulador): JsonResponse
    {
        $calc = $simulador->calcular($request->validated('entradas'), $establecimiento->id);
        $id = (string) Str::uuid7();
        DB::table('simulaciones')->insert([
            'id' => $id, 'establecimiento_id' => $establecimiento->id, 'creado_por' => $request->user()->id, 'nombre' => $request->validated('nombre'),
            'especie_codigo' => $calc['entradas']['especie'] ?? null, 'categoria_codigo' => $calc['entradas']['categoria'] ?? null,
            'cantidad' => $calc['entradas']['cantidad'] ?? null, 'entradas' => json_encode($calc['entradas']), 'resultado' => json_encode($calc['resultado']), 'created_at' => now(),
        ]);

        return response()->json(['data' => self::simulacion(DB::table('simulaciones')->where('id', $id)->first())], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $p = DB::table('simulaciones')->where('establecimiento_id', $establecimiento->id)->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return response()->json(['data' => $p->getCollection()->map(fn ($s) => self::simulacion($s))->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public static function simulacion(object $s): array
    {
        return ['id' => $s->id, 'nombre' => $s->nombre, 'entradas' => json_decode($s->entradas, true), 'resultado' => json_decode($s->resultado, true),
            'created_at' => Formato::fechaHora(Carbon::parse($s->created_at))];
    }
}
