<?php

namespace App\Http\Controllers\Api\V1\Forraje;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Establecimientos\Policies\EstablecimientoPolicy;
use App\Domain\Forraje\BriefForraje;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Forraje\BriefForrajeRequest;
use App\Http\Requests\V1\Forraje\RevisionBriefRequest;
use App\Support\Formato;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Brief preventivo de forraje y carga (TPA-71): insumos, calcular sin guardar, guardar (inmutable), listar, ver y revisión humana.
 * Ver: todo miembro. Crear: dueño/admin/encargado/asesor. Revisar: asesor/dueño/admin (queda el rol de quien revisó).
 */
final class BriefForrajeController extends Controller
{
    public function insumos(Establecimiento $establecimiento, BriefForraje $brief): JsonResponse
    {
        Gate::authorize('forraje.ver', $establecimiento);

        return self::json(['data' => $brief->insumos($establecimiento) + ['aviso' => BriefForraje::AVISO]]);
    }

    public function calcular(BriefForrajeRequest $request, Establecimiento $establecimiento, BriefForraje $brief): JsonResponse
    {
        return self::json(['data' => $brief->calcular($establecimiento, $request->validated())['resultado']]);
    }

    public function store(BriefForrajeRequest $request, Establecimiento $establecimiento, BriefForraje $brief): JsonResponse
    {
        $calc = $brief->calcular($establecimiento, $request->validated());
        $id = (string) Str::uuid7();
        DB::table('forraje_briefs')->insert([
            'id' => $id, 'establecimiento_id' => $establecimiento->id, 'creado_por' => $request->user()->id,
            'nombre' => $request->validated('nombre'), 'horizonte_dias' => $calc['resultado']['horizonte_dias'],
            'entradas' => json_encode($calc['entradas']), 'insumos' => json_encode($calc['insumos']), 'resultado' => json_encode($calc['resultado']), 'created_at' => now(),
        ]);

        return self::json(['data' => self::brief(self::fila($establecimiento, $id), true)], 201);
    }

    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('forraje.ver', $establecimiento);
        $p = self::consulta()->where('b.establecimiento_id', $establecimiento->id)->orderByDesc('b.created_at')->orderByDesc('b.id')
            ->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return self::json(['data' => $p->getCollection()->map(fn ($b) => self::brief($b, false))->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]]);
    }

    public function show(Establecimiento $establecimiento, string $brief): JsonResponse
    {
        Gate::authorize('forraje.ver', $establecimiento);

        return self::json(['data' => self::brief(self::fila($establecimiento, $brief), true)]);
    }

    public function revisar(RevisionBriefRequest $request, Establecimiento $establecimiento, string $brief): JsonResponse
    {
        $fila = self::fila($establecimiento, $brief);
        DB::table('forraje_brief_revisiones')->insert([
            'id' => (string) Str::uuid7(), 'brief_id' => $fila->id, 'establecimiento_id' => $establecimiento->id, 'revisor_id' => $request->user()->id,
            'rol' => EstablecimientoPolicy::rol($request->user(), $establecimiento)?->value ?? 'staff',
            'resultado' => $request->validated('resultado'), 'nota' => $request->validated('nota'), 'created_at' => now(),
        ]);

        return self::json(['data' => self::brief(self::fila($establecimiento, $brief), true)], 201);
    }

    private static function consulta(): Builder
    {
        return DB::table('forraje_briefs as b')->join('usuarios as u', 'u.id', '=', 'b.creado_por')
            ->select('b.*', 'u.nombre as autor_nombre');
    }

    private static function fila(Establecimiento $est, string $id): object
    {
        if (! Str::isUuid($id)) {
            throw ApiException::noEncontrado();
        }

        return self::consulta()->where('b.establecimiento_id', $est->id)->where('b.id', $id)->first() ?? throw ApiException::noEncontrado();
    }

    /** @return array<string, mixed> BriefForraje */
    private static function brief(object $b, bool $completo): array
    {
        $revisiones = DB::table('forraje_brief_revisiones as r')->join('usuarios as u', 'u.id', '=', 'r.revisor_id')
            ->where('r.brief_id', $b->id)->orderBy('r.created_at')->orderBy('r.id')
            ->get(['r.id', 'r.rol', 'r.resultado', 'r.nota', 'r.created_at', 'u.nombre'])
            ->map(fn ($r) => ['id' => $r->id, 'resultado' => $r->resultado, 'nota' => $r->nota, 'rol' => $r->rol, 'revisor' => $r->nombre,
                'created_at' => Formato::fechaHora(Carbon::parse($r->created_at))])->all();
        $ultima = end($revisiones) ?: null;
        $resultado = json_decode($b->resultado, true);
        $salida = [
            'id' => $b->id, 'nombre' => $b->nombre, 'horizonte_dias' => (int) $b->horizonte_dias, 'autor' => $b->autor_nombre,
            'created_at' => Formato::fechaHora(Carbon::parse($b->created_at)),
            'estado_revision' => $ultima['resultado'] ?? 'sin_revisar',
            'revisado_por_asesor' => collect($revisiones)->contains('rol', 'asesor'),
            'resumen' => [
                'cabezas' => $resultado['inventario']['cabezas'] ?? 0, 'ev' => $resultado['inventario']['ev'] ?? '0',
                'escenario_cercano' => $resultado['escenario_cercano_al_clima']['codigo'] ?? 'base',
                'estados' => collect($resultado['escenarios'] ?? [])->mapWithKeys(fn ($e) => [$e['codigo'] => $e['estado']])->all(),
            ],
        ];

        return $completo ? $salida + ['entradas' => json_decode($b->entradas, true), 'resultado' => $resultado, 'revisiones' => $revisiones] : $salida;
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE);
    }
}
