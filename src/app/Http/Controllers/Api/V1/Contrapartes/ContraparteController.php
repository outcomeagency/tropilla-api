<?php

namespace App\Http\Controllers\Api\V1\Contrapartes;

use App\Domain\Contrapartes\ChequeoContraparte;
use App\Domain\Contrapartes\Cuit;
use App\Domain\Contrapartes\Fuentes\FuenteContraparte;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contrapartes\ConsultaContraparteRequest;
use App\Support\Formato;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Chequeo de contraparte por CUIT (TPA-72): consulta asistida, puntual y registrada. Solo dueño/admin (`contrapartes.consultar`).
 * Sin score ni bloqueo; tope diario por establecimiento para que no se use como consulta masiva.
 */
final class ContraparteController extends Controller
{
    /** Texto del consentimiento vigente (se guarda su versión con cada consulta). */
    public const CONSENTIMIENTO_VERSION = '2026-10-17';

    public function fuentes(Establecimiento $establecimiento, ChequeoContraparte $chequeo): JsonResponse
    {
        Gate::authorize('contrapartes.consultar', $establecimiento);

        return self::json(['data' => [
            'fuentes' => array_map(fn (FuenteContraparte $f) => $f->descripcion(), $chequeo->fuentes()),
            'finalidades' => collect(ChequeoContraparte::FINALIDADES)->map(fn ($n, $c) => ['codigo' => $c, 'nombre' => $n])->values()->all(),
            'checklist' => collect(ChequeoContraparte::CHECKLIST)->map(fn ($t, $c) => ['item' => $c, 'texto' => $t])->values()->all(),
            'avisos' => ChequeoContraparte::AVISOS,
            'consentimiento_version' => self::CONSENTIMIENTO_VERSION,
            'revision_legal' => (string) config('tropilla.contrapartes.revision_legal'),
            'max_consultas_dia' => (int) config('tropilla.contrapartes.max_consultas_dia'),
            'consultas_hoy' => self::consultasHoy($establecimiento),
            'retencion_dias' => (int) config('tropilla.contrapartes.retencion_dias'),
        ]]);
    }

    public function store(ConsultaContraparteRequest $request, Establecimiento $establecimiento, ChequeoContraparte $chequeo): JsonResponse
    {
        if (self::consultasHoy($establecimiento) >= (int) config('tropilla.contrapartes.max_consultas_dia')) {
            throw new ApiException(429, 'demasiados_intentos', 'Llegaste al tope de consultas de hoy para este establecimiento. El chequeo es para operaciones puntuales.');
        }
        $cuit = (string) $request->validated('cuit');
        $informe = $chequeo->consultar($cuit);
        $id = (string) Str::uuid7();
        DB::table('contraparte_consultas')->insert([
            'id' => $id, 'establecimiento_id' => $establecimiento->id, 'usuario_id' => $request->user()->id, 'cuit' => $cuit,
            'tipo_persona' => Cuit::tipoPersona($cuit), 'denominacion' => $informe['denominacion'],
            'finalidad' => $request->validated('finalidad'), 'finalidad_detalle' => $request->validated('finalidad_detalle'),
            'consentimiento' => json_encode(['acepta' => true, 'titular_autorizo' => Cuit::tipoPersona($cuit) === 'humana' ? true : $request->boolean('titular_autorizo'),
                'version' => self::CONSENTIMIENTO_VERSION, 'aceptado_en' => now()->toIso8601String()]),
            'informe' => json_encode($informe, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);

        return self::json(['data' => self::consulta(self::fila($establecimiento, $id), true)], 201);
    }

    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('contrapartes.consultar', $establecimiento);
        $p = self::base()->where('c.establecimiento_id', $establecimiento->id)->orderByDesc('c.created_at')->orderByDesc('c.id')
            ->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return self::json(['data' => $p->getCollection()->map(fn ($c) => self::consulta($c, false))->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]]);
    }

    public function show(Establecimiento $establecimiento, string $consulta): JsonResponse
    {
        Gate::authorize('contrapartes.consultar', $establecimiento);

        return self::json(['data' => self::consulta(self::fila($establecimiento, $consulta), true)]);
    }

    public function checklist(Request $request, Establecimiento $establecimiento, string $consulta, string $item): JsonResponse
    {
        Gate::authorize('contrapartes.consultar', $establecimiento);
        $fila = self::fila($establecimiento, $consulta);
        if (! array_key_exists($item, ChequeoContraparte::CHECKLIST)) {
            throw ApiException::noEncontrado();
        }
        $datos = $request->validate(['estado' => ['required', 'in:pendiente,hecho,no_aplica'], 'nota' => ['nullable', 'string', 'max:500']]);
        DB::table('contraparte_checklist')->upsert([
            'consulta_id' => $fila->id, 'item' => $item, 'estado' => $datos['estado'], 'nota' => $datos['nota'] ?? null,
            'actualizado_por' => $request->user()->id, 'updated_at' => now(),
        ], ['consulta_id', 'item'], ['estado', 'nota', 'actualizado_por', 'updated_at']);

        return self::json(['data' => self::consulta(self::fila($establecimiento, $consulta), true)]);
    }

    private static function consultasHoy(Establecimiento $est): int
    {
        return DB::table('contraparte_consultas')->where('establecimiento_id', $est->id)
            ->where('created_at', '>=', Carbon::now($est->zona_horaria)->startOfDay())->count();
    }

    private static function base(): Builder
    {
        return DB::table('contraparte_consultas as c')->join('usuarios as u', 'u.id', '=', 'c.usuario_id')->select('c.*', 'u.nombre as usuario_nombre');
    }

    private static function fila(Establecimiento $est, string $id): object
    {
        if (! Str::isUuid($id)) {
            throw ApiException::noEncontrado();
        }

        return self::base()->where('c.establecimiento_id', $est->id)->where('c.id', $id)->first() ?? throw ApiException::noEncontrado();
    }

    /** @return array<string, mixed> ConsultaContraparte */
    private static function consulta(object $c, bool $completa): array
    {
        $marcas = DB::table('contraparte_checklist')->where('consulta_id', $c->id)->get()->keyBy('item');
        $checklist = collect(ChequeoContraparte::CHECKLIST)->map(fn ($texto, $item) => [
            'item' => $item, 'texto' => $texto, 'estado' => $marcas[$item]->estado ?? 'pendiente', 'nota' => $marcas[$item]->nota ?? null,
            'actualizado_en' => isset($marcas[$item]) ? Formato::fechaHora(Carbon::parse($marcas[$item]->updated_at)) : null,
        ])->values();
        $salida = [
            'id' => $c->id, 'cuit' => Cuit::formatear($c->cuit), 'tipo_persona' => $c->tipo_persona, 'denominacion' => $c->denominacion,
            'finalidad' => $c->finalidad, 'finalidad_nombre' => ChequeoContraparte::FINALIDADES[$c->finalidad] ?? $c->finalidad, 'finalidad_detalle' => $c->finalidad_detalle,
            'consultado_por' => $c->usuario_nombre, 'created_at' => Formato::fechaHora(Carbon::parse($c->created_at)),
            'depurada_en' => $c->depurada_en ? Formato::fechaHora(Carbon::parse($c->depurada_en)) : null,
            'checklist_hechos' => $checklist->whereIn('estado', ['hecho', 'no_aplica'])->count(), 'checklist_total' => $checklist->count(),
        ];

        return $completa ? $salida + [
            'consentimiento' => json_decode($c->consentimiento, true),
            'informe' => $c->informe ? json_decode($c->informe, true) : null,
            'checklist' => $checklist->all(),
        ] : $salida;
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE);
    }
}
