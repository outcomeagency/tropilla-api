<?php

namespace App\Http\Controllers\Api\V1\Fletes;

use App\Domain\Establecimientos\Data\Provincias;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Fletes\Fletes;
use App\Domain\Fletes\GestionFletes;
use App\Domain\Fletes\PresentadorFletes;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Fletes\PedidoFleteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Pedidos de flete del productor (TPA-73, piloto concierge). Ver: `fletes.ver`; pedir, decidir y registrar resultado: `fletes.gestionar`.
 * El contacto del transportista aparece recién cuando el productor acepta su propuesta.
 */
final class PedidoFleteController extends Controller
{
    public const MOTIVOS_RECHAZO = ['precio' => 'Precio', 'fecha' => 'Fecha u horario', 'camion' => 'Camión o capacidad',
        'ya_consegui' => 'Ya conseguí transporte', 'otro' => 'Otro'];

    public function opciones(Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('fletes.ver', $establecimiento);

        return self::json(['data' => self::catalogos() + [
            'origen_sugerido' => ['departamento_codigo' => $establecimiento->departamento_codigo,
                'departamento_nombre' => Fletes::departamentoNombre($establecimiento->departamento_codigo)],
            'en_corredor' => in_array($establecimiento->provincia_codigo, Fletes::corredor(), true),
            'departamentos' => DB::table('geo_departamentos')->orderBy('provincia_nombre')->orderBy('nombre')->get(['codigo', 'nombre', 'provincia_nombre'])->all(),
            'motivos_rechazo' => collect(self::MOTIVOS_RECHAZO)->map(fn ($n, $c) => ['codigo' => $c, 'nombre' => $n])->values()->all(),
        ]]);
    }

    /** @return array<string, mixed> */
    public static function catalogos(): array
    {
        $lista = fn (array $m) => collect($m)->map(fn ($n, $c) => ['codigo' => $c, 'nombre' => $n])->values()->all();

        return [
            'corredor' => Fletes::corredorInfo(),
            'motivos' => $lista(Fletes::MOTIVOS),
            'jaulas' => $lista(Fletes::JAULAS),
            'provincias' => $lista(Provincias::TODAS),
            'checklist' => collect(Fletes::CHECKLIST_PEDIDO)->map(fn ($t, $c) => ['item' => $c, 'texto' => $t])->values()->all(),
            'documentos_transportista' => collect(Fletes::DOCUMENTOS_TRANSPORTISTA)->map(fn ($t, $c) => ['item' => $c, 'texto' => $t])->values()->all(),
            'avisos' => Fletes::AVISOS,
            'consentimiento' => ['texto' => Fletes::CONSENTIMIENTO, 'version' => Fletes::CONSENTIMIENTO_VERSION],
            'revision_legal' => (string) config('tropilla.fletes.revision_legal'),
            'emite_dte' => false,
        ];
    }

    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('fletes.ver', $establecimiento);
        $q = PresentadorFletes::basePedidos()->where('p.establecimiento_id', $establecimiento->id);
        match ($request->query('estado')) {
            'abiertos' => $q->whereIn('p.estado', Fletes::ESTADOS_ABIERTOS),
            'cerrados' => $q->whereNotIn('p.estado', Fletes::ESTADOS_ABIERTOS),
            default => null,
        };
        $p = $q->orderByDesc('p.created_at')->orderByDesc('p.id')->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return self::json(['data' => $p->getCollection()->map(fn ($f) => PresentadorFletes::pedido($f, false))->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]]);
    }

    public function store(PedidoFleteRequest $request, Establecimiento $establecimiento, GestionFletes $gestion): JsonResponse
    {
        $id = $gestion->crearPedido($establecimiento, $request->user(), $request->validated());

        return self::json(['data' => PresentadorFletes::pedido(self::fila($establecimiento, $id), true)], 201);
    }

    public function show(Establecimiento $establecimiento, string $pedido): JsonResponse
    {
        Gate::authorize('fletes.ver', $establecimiento);

        return self::json(['data' => PresentadorFletes::pedido(self::fila($establecimiento, $pedido), true)]);
    }

    public function cancelar(Request $request, Establecimiento $establecimiento, string $pedido, GestionFletes $gestion): JsonResponse
    {
        Gate::authorize('fletes.gestionar', $establecimiento);
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $gestion->cancelar(self::fila($establecimiento, $pedido), $request->user(), $datos['motivo'] ?? null);

        return self::json(['data' => PresentadorFletes::pedido(self::fila($establecimiento, $pedido), true)]);
    }

    public function aceptar(Request $request, Establecimiento $establecimiento, string $pedido, string $propuesta, GestionFletes $gestion): JsonResponse
    {
        Gate::authorize('fletes.gestionar', $establecimiento);
        $fila = self::fila($establecimiento, $pedido);
        $gestion->aceptar($fila, self::propuesta($fila, $propuesta), $request->user());

        return self::json(['data' => PresentadorFletes::pedido(self::fila($establecimiento, $pedido), true)]);
    }

    public function rechazar(Request $request, Establecimiento $establecimiento, string $pedido, string $propuesta, GestionFletes $gestion): JsonResponse
    {
        Gate::authorize('fletes.gestionar', $establecimiento);
        $datos = $request->validate(['motivo' => ['required', 'in:'.implode(',', array_keys(self::MOTIVOS_RECHAZO))], 'detalle' => ['nullable', 'string', 'max:300']]);
        $fila = self::fila($establecimiento, $pedido);
        $motivo = self::MOTIVOS_RECHAZO[$datos['motivo']].(empty($datos['detalle']) ? '' : ': '.$datos['detalle']);
        $gestion->rechazar($fila, self::propuesta($fila, $propuesta), $request->user(), $motivo);

        return self::json(['data' => PresentadorFletes::pedido(self::fila($establecimiento, $pedido), true)]);
    }

    public function resultado(Request $request, Establecimiento $establecimiento, string $pedido, GestionFletes $gestion): JsonResponse
    {
        Gate::authorize('fletes.gestionar', $establecimiento);
        $gestion->resultado(self::fila($establecimiento, $pedido), $request->user(), self::validarResultado($request));

        return self::json(['data' => PresentadorFletes::pedido(self::fila($establecimiento, $pedido), true)]);
    }

    public function checklist(Request $request, Establecimiento $establecimiento, string $pedido, string $item): JsonResponse
    {
        Gate::authorize('fletes.gestionar', $establecimiento);
        $fila = self::fila($establecimiento, $pedido);
        if (! array_key_exists($item, Fletes::CHECKLIST_PEDIDO)) {
            throw ApiException::noEncontrado();
        }
        $datos = $request->validate(['estado' => ['required', 'in:pendiente,hecho,no_aplica'], 'nota' => ['nullable', 'string', 'max:500']]);
        DB::table('fletes_pedido_checklist')->upsert([
            'pedido_id' => $fila->id, 'item' => $item, 'estado' => $datos['estado'], 'nota' => $datos['nota'] ?? null,
            'actualizado_por' => $request->user()->id, 'updated_at' => now(),
        ], ['pedido_id', 'item'], ['estado', 'nota', 'actualizado_por', 'updated_at']);

        return self::json(['data' => PresentadorFletes::pedido(self::fila($establecimiento, $pedido), true)]);
    }

    /** @return array<string, mixed> */
    public static function validarResultado(Request $request): array
    {
        return $request->validate([
            'resultado' => ['required', 'in:acordado,sin_acuerdo'],
            'costo' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'km' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private static function fila(Establecimiento $est, string $id): object
    {
        if (! Str::isUuid($id)) {
            throw ApiException::noEncontrado();
        }

        return PresentadorFletes::basePedidos()->where('p.establecimiento_id', $est->id)->where('p.id', $id)->first() ?? throw ApiException::noEncontrado();
    }

    private static function propuesta(object $pedido, string $id): object
    {
        if (! Str::isUuid($id)) {
            throw ApiException::noEncontrado();
        }

        return DB::table('fletes_propuestas')->where('pedido_id', $pedido->id)->where('id', $id)->first() ?? throw ApiException::noEncontrado();
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE);
    }
}
