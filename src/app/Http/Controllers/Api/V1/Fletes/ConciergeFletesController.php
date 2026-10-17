<?php

namespace App\Http\Controllers\Api\V1\Fletes;

use App\Domain\Contrapartes\Cuit;
use App\Domain\Establecimientos\Data\Provincias;
use App\Domain\Fletes\Fletes;
use App\Domain\Fletes\GestionFletes;
use App\Domain\Fletes\MatchingFletes;
use App\Domain\Fletes\PresentadorFletes;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Mesa del concierge de fletes (TPA-73): solo equipo de TROPILLA (`es_staff`). Carga transportistas y su disponibilidad,
 * verifica a mano la documentación (quién, cuándo, cómo), ve las sugerencias de matching y propone camiones a los pedidos.
 * Las métricas del piloto miden pedidos, tiempo hasta la primera propuesta, acuerdos y costo por km.
 */
final class ConciergeFletesController extends Controller
{
    public function resumen(Request $request): JsonResponse
    {
        self::staff($request);
        $porEstado = DB::table('fletes_pedidos')->selectRaw('estado, count(*) as n')->groupBy('estado')->pluck('n', 'estado');
        $acordados = (int) ($porEstado['acordado'] ?? 0);
        $sinAcuerdo = (int) ($porEstado['sin_acuerdo'] ?? 0);
        $horas = DB::selectOne('SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY h) AS mediana, count(*) AS n FROM (
            SELECT EXTRACT(EPOCH FROM (min(pr.created_at) - p.created_at)) / 3600 AS h FROM fletes_pedidos p JOIN fletes_propuestas pr ON pr.pedido_id = p.id GROUP BY p.id, p.created_at) x');
        $costo = DB::table('fletes_pedidos')->where('estado', 'acordado')->whereNotNull('resultado_costo')->whereNotNull('resultado_km')
            ->selectRaw('sum(resultado_costo) as costo, sum(resultado_km) as km, count(*) as n')->first();
        $transportistas = DB::table('fletes_transportistas')->where('activo', true)->pluck('id');
        $rechazos = DB::table('fletes_propuestas')->where('estado', 'rechazada')->whereNotNull('motivo_rechazo')
            ->selectRaw("split_part(motivo_rechazo, ':', 1) as motivo, count(*) as n")->groupBy('motivo')->orderByDesc('n')->get();

        return PedidoFleteController::json(['data' => PedidoFleteController::catalogos() + [
            'departamentos' => DB::table('geo_departamentos')->orderBy('provincia_nombre')->orderBy('nombre')->get(['codigo', 'nombre', 'provincia_nombre'])->all(),
            'metodos_verificacion' => collect(Fletes::METODOS_VERIFICACION)->map(fn ($n, $c) => ['codigo' => $c, 'nombre' => $n])->values()->all(),
            'metricas' => [
                'pedidos_total' => (int) $porEstado->sum(),
                'pedidos_por_estado' => collect(['pendiente', 'en_gestion', 'propuesto', 'contactado', 'acordado', 'sin_acuerdo', 'cancelado'])
                    ->mapWithKeys(fn ($e) => [$e => (int) ($porEstado[$e] ?? 0)])->all(),
                'pedidos_fuera_corredor' => DB::table('fletes_pedidos')->where('en_corredor', false)->count(),
                'tasa_acuerdo_pct' => $acordados + $sinAcuerdo > 0 ? (int) round($acordados * 100 / ($acordados + $sinAcuerdo)) : null,
                'horas_hasta_primera_propuesta' => $horas && $horas->n > 0 ? Formato::decimal(round((float) $horas->mediana, 1)) : null,
                'costo_por_km' => $costo && $costo->km > 0 ? Formato::decimal(round((float) $costo->costo / (int) $costo->km, 2)) : null,
                'acuerdos_con_costo' => (int) ($costo->n ?? 0),
                'cabezas_acordadas' => (int) DB::table('fletes_pedidos')->where('estado', 'acordado')->sum('cabezas'),
                'transportistas_activos' => $transportistas->count(),
                'transportistas_documentados' => $transportistas->filter(fn ($id) => Fletes::documentacion($id)['completa'])->count(),
                'disponibilidades_vigentes' => DB::table('fletes_disponibilidades')->where('estado', 'abierta')->where('hasta', '>=', Carbon::today()->toDateString())->count(),
                'rechazos_por_motivo' => $rechazos->map(fn ($r) => ['motivo' => $r->motivo, 'n' => (int) $r->n])->all(),
            ],
        ]]);
    }

    public function pedidos(Request $request): JsonResponse
    {
        self::staff($request);
        $estado = (string) ($request->query('estado') ?: 'abiertos');
        $q = PresentadorFletes::basePedidos();
        match ($estado) {
            'abiertos' => $q->whereIn('p.estado', Fletes::ESTADOS_ABIERTOS)->orderBy('p.fecha_desde')->orderBy('p.created_at'),
            'cerrados' => $q->whereNotIn('p.estado', Fletes::ESTADOS_ABIERTOS)->orderByDesc('p.updated_at'),
            default => in_array($estado, ['pendiente', 'en_gestion', 'propuesto', 'contactado', 'acordado', 'sin_acuerdo', 'cancelado'], true)
                ? $q->where('p.estado', $estado)->orderByDesc('p.created_at') : throw ApiException::validacion('Estado inválido.', ['estado' => ['Estado inválido.']]),
        };

        return PedidoFleteController::json(['data' => $q->orderBy('p.id')->limit(200)->get()->map(fn ($p) => PresentadorFletes::pedido($p, false, true))->all()]);
    }

    public function pedido(Request $request, string $pedido, MatchingFletes $matching): JsonResponse
    {
        self::staff($request);
        $fila = self::filaPedido($pedido);

        return PedidoFleteController::json(['data' => PresentadorFletes::pedido($fila, true, true) + [
            'sugerencias' => in_array($fila->estado, ['pendiente', 'en_gestion', 'propuesto'], true) ? $matching->sugerencias($fila) : [],
        ]]);
    }

    public function tomar(Request $request, string $pedido, GestionFletes $gestion): JsonResponse
    {
        self::staff($request);
        $gestion->tomar(self::filaPedido($pedido), $request->user());

        return $this->pedido($request, $pedido, app(MatchingFletes::class));
    }

    public function proponer(Request $request, string $pedido, GestionFletes $gestion): JsonResponse
    {
        self::staff($request);
        $datos = $request->validate([
            'transportista_id' => ['required', 'uuid'],
            'disponibilidad_id' => ['nullable', 'uuid'],
            'fecha_propuesta' => ['required', 'date_format:Y-m-d'],
            'tarifa_estimada' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);
        $gestion->proponer(self::filaPedido($pedido), $request->user(), $datos);

        return $this->pedido($request, $pedido, app(MatchingFletes::class))->setStatusCode(201);
    }

    public function retirar(Request $request, string $propuesta, GestionFletes $gestion): JsonResponse
    {
        self::staff($request);
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $fila = Str::isUuid($propuesta) ? DB::table('fletes_propuestas')->where('id', $propuesta)->first() : null;
        $fila ?? throw ApiException::noEncontrado();
        $gestion->retirar($fila, $request->user(), $datos['motivo'] ?? null);

        return $this->pedido($request, $fila->pedido_id, app(MatchingFletes::class));
    }

    public function resultado(Request $request, string $pedido, GestionFletes $gestion): JsonResponse
    {
        self::staff($request);
        $gestion->resultado(self::filaPedido($pedido), $request->user(), PedidoFleteController::validarResultado($request));

        return $this->pedido($request, $pedido, app(MatchingFletes::class));
    }

    public function transportistas(Request $request): JsonResponse
    {
        self::staff($request);

        return PedidoFleteController::json(['data' => DB::table('fletes_transportistas')->orderByDesc('activo')->orderBy('nombre')->limit(500)->get()
            ->map(fn ($t) => PresentadorFletes::transportista($t, false))->all()]);
    }

    public function transportista(Request $request, string $transportista): JsonResponse
    {
        self::staff($request);

        return PedidoFleteController::json(['data' => PresentadorFletes::transportista(self::filaTransportista($transportista), true)]);
    }

    public function crearTransportista(Request $request): JsonResponse
    {
        self::staff($request);
        $datos = self::validarTransportista($request, true);
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($request, $datos, $id) {
            DB::table('fletes_transportistas')->insert(self::columnasTransportista($datos) + [
                'id' => $id, 'activo' => true, 'cargado_por' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                'consentimiento' => json_encode(['acepta' => true, 'texto' => 'El transportista aceptó figurar en el piloto y que su contacto se comparta con productores que acepten su propuesta.',
                    'registrado_por' => $request->user()->id, 'registrado_en' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE),
            ]);
            Fletes::bitacora($request->user()->id, 'transportista_alta', null, $id);
        });

        return PedidoFleteController::json(['data' => PresentadorFletes::transportista(self::filaTransportista($id), true)], 201);
    }

    public function actualizarTransportista(Request $request, string $transportista): JsonResponse
    {
        self::staff($request);
        $fila = self::filaTransportista($transportista);
        $datos = self::validarTransportista($request, false);
        DB::transaction(function () use ($request, $datos, $fila) {
            DB::table('fletes_transportistas')->where('id', $fila->id)->update(self::columnasTransportista($datos)
                + (array_key_exists('activo', $datos) ? ['activo' => (bool) $datos['activo']] : []) + ['updated_at' => now()]);
            Fletes::bitacora($request->user()->id, 'transportista_editado', null, $fila->id, ['campos' => array_keys($datos)]);
        });

        return PedidoFleteController::json(['data' => PresentadorFletes::transportista(self::filaTransportista($fila->id), true)]);
    }

    public function documento(Request $request, string $transportista, string $item): JsonResponse
    {
        self::staff($request);
        $fila = self::filaTransportista($transportista);
        if (! array_key_exists($item, Fletes::DOCUMENTOS_TRANSPORTISTA)) {
            throw ApiException::noEncontrado();
        }
        $datos = $request->validate([
            'estado' => ['required', 'in:pendiente,verificado,observado,no_aplica'],
            'metodo' => ['nullable', 'required_if:estado,verificado', Rule::in(array_keys(Fletes::METODOS_VERIFICACION))],
            'vence_el' => ['nullable', 'date_format:Y-m-d'],
            'nota' => ['nullable', 'string', 'max:500', 'required_if:estado,observado'],
        ], ['metodo.required_if' => 'Indicá cómo lo verificaste.', 'nota.required_if' => 'Contá qué se observó.']);
        if ($item === 'identidad_cuit' && $datos['estado'] === 'no_aplica') {
            throw ApiException::validacion('La identidad del transportista siempre se verifica.', ['estado' => ['La identidad del transportista siempre se verifica.']]);
        }
        if ($datos['estado'] === 'verificado' && ! empty($datos['vence_el']) && $datos['vence_el'] < Carbon::today()->toDateString()) {
            throw ApiException::validacion('El documento está vencido: no se puede marcar como verificado.', ['vence_el' => ['El documento está vencido.']]);
        }
        DB::transaction(function () use ($request, $fila, $item, $datos) {
            DB::table('fletes_transportista_documentos')->upsert([
                'transportista_id' => $fila->id, 'item' => $item, 'estado' => $datos['estado'], 'metodo' => $datos['metodo'] ?? null,
                'vence_el' => $datos['vence_el'] ?? null, 'nota' => $datos['nota'] ?? null, 'verificado_por' => $request->user()->id, 'updated_at' => now(),
            ], ['transportista_id', 'item'], ['estado', 'metodo', 'vence_el', 'nota', 'verificado_por', 'updated_at']);
            Fletes::bitacora($request->user()->id, 'documento_'.$datos['estado'], null, $fila->id, ['item' => $item, 'metodo' => $datos['metodo'] ?? null,
                'vence_el' => $datos['vence_el'] ?? null]);
        });

        return PedidoFleteController::json(['data' => PresentadorFletes::transportista(self::filaTransportista($fila->id), true)]);
    }

    public function disponibilidades(Request $request): JsonResponse
    {
        self::staff($request);

        return PedidoFleteController::json(['data' => DB::table('fletes_disponibilidades as d')->join('fletes_transportistas as t', 't.id', '=', 'd.transportista_id')
            ->where('d.estado', 'abierta')->where('d.hasta', '>=', Carbon::today()->toDateString())->orderBy('d.desde')->limit(300)
            ->get(['d.*', 't.nombre as transportista_nombre'])->map(fn ($d) => PresentadorFletes::disponibilidad($d))->all()]);
    }

    public function crearDisponibilidad(Request $request, string $transportista): JsonResponse
    {
        self::staff($request);
        $fila = self::filaTransportista($transportista);
        $datos = $request->validate([
            'origen_departamento_codigo' => ['required', 'string', 'regex:/^\d{5}$/'],
            'destino_provincia_codigo' => ['nullable', Rule::in(array_keys(Provincias::TODAS))],
            'desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'jaula' => ['required', Rule::in(array_keys(Fletes::JAULAS))],
            'capacidad_cabezas' => ['required', 'integer', 'min:1', 'max:500'],
            'especies' => ['sometimes', 'array', 'min:1'],
            'especies.*' => ['in:BOV,BUB'],
            'es_retorno' => ['sometimes', 'boolean'],
            'tarifa_km' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'notas' => ['nullable', 'string', 'max:500'],
        ]);
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($request, $fila, $datos, $id) {
            DB::table('fletes_disponibilidades')->insert([
                'id' => $id, 'transportista_id' => $fila->id, 'origen_departamento_codigo' => $datos['origen_departamento_codigo'],
                'destino_provincia_codigo' => $datos['destino_provincia_codigo'] ?? null, 'desde' => $datos['desde'], 'hasta' => $datos['hasta'],
                'jaula' => $datos['jaula'], 'capacidad_cabezas' => $datos['capacidad_cabezas'],
                'especies' => '{'.implode(',', array_values(array_unique($datos['especies'] ?? ['BOV', 'BUB']))).'}',
                'es_retorno' => (bool) ($datos['es_retorno'] ?? false), 'tarifa_km' => $datos['tarifa_km'] ?? null, 'notas' => $datos['notas'] ?? null,
                'estado' => 'abierta', 'cargado_por' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Fletes::bitacora($request->user()->id, 'disponibilidad_alta', null, $fila->id, ['disponibilidad_id' => $id]);
        });

        return PedidoFleteController::json(['data' => PresentadorFletes::transportista(self::filaTransportista($fila->id), true)], 201);
    }

    public function cerrarDisponibilidad(Request $request, string $disponibilidad): JsonResponse
    {
        self::staff($request);
        $d = Str::isUuid($disponibilidad) ? DB::table('fletes_disponibilidades')->where('id', $disponibilidad)->first() : null;
        $d ?? throw ApiException::noEncontrado();
        DB::transaction(function () use ($request, $d) {
            DB::table('fletes_disponibilidades')->where('id', $d->id)->update(['estado' => 'cerrada', 'updated_at' => now()]);
            Fletes::bitacora($request->user()->id, 'disponibilidad_cerrada', null, $d->transportista_id, ['disponibilidad_id' => $d->id]);
        });

        return PedidoFleteController::json(['data' => PresentadorFletes::transportista(self::filaTransportista($d->transportista_id), true)]);
    }

    /** @return array<string, mixed> */
    private static function validarTransportista(Request $request, bool $alta): array
    {
        $req = $alta ? 'required' : 'sometimes';
        $datos = $request->validate([
            'nombre' => [$req, 'string', 'max:160'],
            'cuit' => ['nullable', 'string', 'max:13'],
            'contacto_nombre' => [$req, 'string', 'max:120'],
            'telefono' => [$req, 'string', 'regex:/^[0-9 +()\-]{6,30}$/'],
            'email' => ['nullable', 'email', 'max:160'],
            'base_departamento_codigo' => [$req, 'string', 'regex:/^\d{5}$/'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'activo' => ['sometimes', 'boolean'],
            'consentimiento' => $alta ? ['required', 'accepted'] : ['prohibited'],
        ], ['consentimiento.required' => 'Confirmá que el transportista aceptó figurar en el piloto.']);
        if (! empty($datos['cuit'])) {
            $datos['cuit'] = Cuit::normalizar($datos['cuit']);
            if (! Cuit::valido($datos['cuit'])) {
                throw ApiException::validacion('El CUIT no es válido (revisá el dígito verificador).', ['cuit' => ['El CUIT no es válido (revisá el dígito verificador).']]);
            }
        }
        unset($datos['consentimiento']);

        return $datos;
    }

    /** @param array<string, mixed> $datos @return array<string, mixed> */
    private static function columnasTransportista(array $datos): array
    {
        return array_intersect_key($datos, array_flip(['nombre', 'cuit', 'contacto_nombre', 'telefono', 'email', 'base_departamento_codigo', 'notas']));
    }

    private static function staff(Request $request): void
    {
        $request->user()->es_staff || throw ApiException::sinPermiso('La mesa de fletes es del equipo de TROPILLA.');
    }

    private static function filaPedido(string $id): object
    {
        if (! Str::isUuid($id)) {
            throw ApiException::noEncontrado();
        }

        return PresentadorFletes::basePedidos()->where('p.id', $id)->first() ?? throw ApiException::noEncontrado();
    }

    private static function filaTransportista(string $id): object
    {
        if (! Str::isUuid($id)) {
            throw ApiException::noEncontrado();
        }

        return DB::table('fletes_transportistas')->where('id', $id)->first() ?? throw ApiException::noEncontrado();
    }
}
