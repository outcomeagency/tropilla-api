<?php

namespace App\Http\Controllers\Api\V1\Comercial;

use App\Domain\Comercial\ConsultasOperaciones;
use App\Domain\Comercial\HistorialPrecios;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Http\Controllers\Controller;
use App\Rules\Reglas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Historial de precios propio y precios de referencia declarados (TPA-47). Solo dueño/admin (`comercial.precios`). */
class PrecioController extends Controller
{
    public function historial(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $f = $request->validate(['especie' => ['sometimes', 'exists:especies,codigo'], 'categoria' => ['sometimes', 'exists:categorias,codigo'],
            'desde' => ['sometimes', 'date_format:Y-m-d'], 'hasta' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:desde'], 'referencia' => ['sometimes', 'string', 'max:120']]);

        return response()->json(['data' => HistorialPrecios::de($establecimiento->id, $f)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function referencias(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $f = $request->validate(['especie' => ['sometimes', 'exists:especies,codigo']]);
        $p = DB::table('precios_referencia')->where('establecimiento_id', $establecimiento->id)
            ->when($f['especie'] ?? null, fn ($q, $v) => $q->where('especie_codigo', $v))
            ->orderByDesc('fecha')->orderByDesc('id')->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return response()->json(['data' => $p->getCollection()->map(fn ($r) => ConsultasOperaciones::referencia($r))->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function crearReferencia(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $d = $request->validate([
            'descripcion' => ['required', 'string', 'min:3', 'max:120'], 'especie' => ['required', 'exists:especies,codigo'],
            'categoria' => ['sometimes', 'nullable', Rule::exists('categorias', 'codigo')->where('especie_codigo', $request->input('especie'))],
            'modalidad' => ['required', 'in:en_pie,al_gancho'], 'precio_kg' => ['required', 'string', Reglas::DECIMAL_POS, 'numeric', 'gt:0'],
            'moneda' => ['sometimes', 'in:ARS,USD'], 'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'fuente' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $id = (string) Str::uuid7();
        DB::table('precios_referencia')->insert([
            'id' => $id, 'establecimiento_id' => $establecimiento->id, 'descripcion' => $d['descripcion'], 'especie_codigo' => $d['especie'],
            'categoria_codigo' => $d['categoria'] ?? null, 'modalidad' => $d['modalidad'], 'precio_kg' => $d['precio_kg'], 'moneda' => $d['moneda'] ?? 'ARS',
            'fecha' => $d['fecha'], 'fuente' => $d['fuente'] ?? null, 'creado_por' => $request->user()->id, 'created_at' => now(),
        ]);

        return response()->json(['data' => ConsultasOperaciones::referencia(DB::table('precios_referencia')->where('id', $id)->first())], 201, [], JSON_UNESCAPED_UNICODE);
    }
}
