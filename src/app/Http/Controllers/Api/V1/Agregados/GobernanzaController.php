<?php

namespace App\Http\Controllers\Api\V1\Agregados;

use App\Domain\Agregados\DatasetSintetico;
use App\Domain\Agregados\Gobernanza;
use App\Domain\Agregados\MotorAgregados;
use App\Domain\Agregados\PruebaReidentificacion;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gobernanza del tablero agregado (TPA-75), solo equipo TROPILLA (otro usuario → 404): política de divulgación versionada,
 * pruebas de reidentificación, revisiones del gate, licencias de clientes institucionales y sus integrantes, uso y bitácora.
 */
final class GobernanzaController extends Controller
{
    public function show(Request $request, DatasetSintetico $dataset): JsonResponse
    {
        Gobernanza::soloStaff($request->user());
        $filas = $dataset->asegurar();
        $politica = Gobernanza::politica();
        $desde = now()->subDays(30);
        $uso = DB::table('agregados_consultas')->where('created_at', '>=', $desde)
            ->selectRaw("count(*) as total, count(*) filter (where tipo = 'exportacion') as exportaciones, coalesce(sum(celdas), 0) as celdas, coalesce(sum(suprimidas), 0) as suprimidas")->first();
        $ultima = DB::table('agregados_pruebas')->orderByDesc('created_at')->orderByDesc('id')->first();

        return self::json(['data' => [
            'politica' => TableroController::politica($politica),
            'politicas' => DB::table('agregados_politicas')->orderByDesc('version')->get()->map(fn ($p) => TableroController::politica($p))->all(),
            'gate' => Gobernanza::gate($politica),
            'ultima_prueba' => $ultima ? self::resumenPrueba($ultima, false) : null,
            'dataset' => ['version' => DatasetSintetico::VERSION, 'fuente' => 'sintetico', 'filas' => $filas, 'trimestres' => DatasetSintetico::TRIMESTRES,
                'establecimientos' => DB::table('agregados_sinteticos')->where('dataset_version', DatasetSintetico::VERSION)->distinct()->count('establecimiento_ref')],
            'uso_30_dias' => ['consultas' => (int) $uso->total, 'exportaciones' => (int) $uso->exportaciones,
                'suprimidas_pct' => $uso->celdas > 0 ? (int) round($uso->suprimidas / $uso->celdas * 100) : 0],
            'bitacora' => DB::table('agregados_bitacora as b')->join('usuarios as u', 'u.id', '=', 'b.usuario_id')->leftJoin('agregados_clientes as c', 'c.id', '=', 'b.cliente_id')
                ->orderByDesc('b.id')->limit(30)->get(['b.*', 'u.nombre as usuario', 'c.nombre as cliente'])
                ->map(fn ($b) => ['id' => (int) $b->id, 'accion' => $b->accion, 'usuario' => $b->usuario, 'cliente' => $b->cliente,
                    'detalle' => json_decode($b->detalle, true), 'created_at' => Formato::fechaHora(Carbon::parse($b->created_at))])->all(),
        ]]);
    }

    /** Nueva versión de la política: invalida las revisiones del gate (están atadas a la versión). */
    public function politica(Request $request): JsonResponse
    {
        Gobernanza::soloStaff($u = $request->user());
        $d = $request->validate([
            'k_minimo' => ['required', 'integer', 'between:5,100'], 'dominancia_max_pct' => ['required', 'integer', 'between:30,90'],
            'redondeo_cabezas' => ['required', 'integer', 'in:10,50,100,500'], 'consultas_por_dia' => ['required', 'integer', 'between:1,500'],
            'desglose_escala' => ['required', 'boolean'], 'nota' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $p = DB::transaction(function () use ($u, $d) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['agregados|politica']);
            $actual = Gobernanza::politica();
            DB::table('agregados_politicas')->insert([...$d, 'version' => $actual->version + 1, 'creado_por' => $u->id, 'created_at' => now()]);
            self::bitacora($u, null, 'politica_nueva', ['version' => $actual->version + 1, 'antes' => array_intersect_key((array) $actual, $d), 'despues' => $d]);

            return Gobernanza::politica();
        });

        return self::json(['data' => TableroController::politica($p)], 201);
    }

    public function pruebas(Request $request): JsonResponse
    {
        Gobernanza::soloStaff($request->user());

        return self::json(['data' => DB::table('agregados_pruebas as p')->join('usuarios as u', 'u.id', '=', 'p.ejecutada_por')
            ->orderByDesc('p.created_at')->orderByDesc('p.id')->limit(20)->get(['p.*', 'u.nombre as ejecutada_por_nombre'])->map(fn ($p) => self::resumenPrueba($p, false))->all()]);
    }

    public function prueba(Request $request, string $prueba): JsonResponse
    {
        Gobernanza::soloStaff($request->user());
        $p = (Str::isUuid($prueba) ? DB::table('agregados_pruebas as p')->join('usuarios as u', 'u.id', '=', 'p.ejecutada_por')
            ->where('p.id', strtolower($prueba))->first(['p.*', 'u.nombre as ejecutada_por_nombre']) : null) ?? throw ApiException::noEncontrado();

        return self::json(['data' => self::resumenPrueba($p, true)]);
    }

    public function ejecutarPrueba(Request $request, DatasetSintetico $dataset, PruebaReidentificacion $prueba): JsonResponse
    {
        Gobernanza::soloStaff($u = $request->user());
        $dataset->asegurar();
        $politica = Gobernanza::politica();
        $r = $prueba->ejecutar($politica);
        $id = (string) Str::uuid7();
        DB::table('agregados_pruebas')->insert(['id' => $id, 'politica_version' => $politica->version, 'dataset_version' => DatasetSintetico::VERSION,
            'resultado' => $r['resultado'], 'controles' => json_encode($r['controles'], JSON_UNESCAPED_UNICODE), 'ejecutada_por' => $u->id, 'created_at' => now()]);
        self::bitacora($u, null, 'prueba_reidentificacion', ['prueba_id' => $id, 'resultado' => $r['resultado'], 'politica_version' => (int) $politica->version]);

        return self::json(['data' => self::resumenPrueba(DB::table('agregados_pruebas as p')->join('usuarios as u', 'u.id', '=', 'p.ejecutada_por')
            ->where('p.id', $id)->first(['p.*', 'u.nombre as ejecutada_por_nombre']), true)], 201);
    }

    /** Revisión de un aspecto del gate. Aprobar reidentificación exige una prueba aprobada bajo la política vigente. */
    public function revisar(Request $request): JsonResponse
    {
        Gobernanza::soloStaff($u = $request->user());
        $d = $request->validate(['aspecto' => ['required', 'in:'.implode(',', Gobernanza::ASPECTOS)], 'resultado' => ['required', 'in:aprobado,observado'],
            'nota' => ['required', 'string', 'min:10', 'max:2000']]);
        $politica = Gobernanza::politica();
        $pruebaId = null;
        if ($d['aspecto'] === 'reidentificacion') {
            $ultima = DB::table('agregados_pruebas')->where('politica_version', $politica->version)->where('dataset_version', DatasetSintetico::VERSION)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($d['resultado'] === 'aprobado' && $ultima?->resultado !== 'aprobada') {
                throw new ApiException(409, 'prueba_pendiente', __('Para aprobar la reidentificación, la última prueba de la política vigente tiene que estar aprobada.'));
            }
            $pruebaId = $ultima?->id;
        }
        DB::table('agregados_revisiones')->insert(['id' => (string) Str::uuid7(), ...$d, 'politica_version' => $politica->version, 'prueba_id' => $pruebaId,
            'revisor_id' => $u->id, 'created_at' => now()]);
        self::bitacora($u, null, 'revision_gate', [...$d, 'politica_version' => (int) $politica->version]);

        return self::json(['data' => Gobernanza::gate($politica)], 201);
    }

    public function clientes(Request $request): JsonResponse
    {
        Gobernanza::soloStaff($request->user());
        $politica = Gobernanza::politica();

        return self::json(['data' => DB::table('agregados_clientes')->orderBy('nombre')->orderBy('id')->get()->map(fn ($c) => self::cliente($c, $politica))->all()]);
    }

    public function crearCliente(Request $request): JsonResponse
    {
        Gobernanza::soloStaff($u = $request->user());
        $d = $request->validate(self::reglasCliente(true));
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($u, $d, $id) {
            DB::table('agregados_clientes')->insert(['id' => $id, 'nombre' => $d['nombre'], 'tipo' => $d['tipo'], 'finalidad' => $d['finalidad'],
                'contrato_version' => $d['contrato_version'], 'vigente_hasta' => $d['vigente_hasta'] ?? null, 'consultas_por_dia' => $d['consultas_por_dia'] ?? null,
                'metricas' => '{'.implode(',', array_unique($d['metricas'])).'}', 'estado' => 'activo', 'creado_por' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            self::bitacora($u, $id, 'licencia_alta', $d);
        });

        return self::json(['data' => self::cliente(Gobernanza::cliente($id), Gobernanza::politica())], 201);
    }

    public function actualizarCliente(Request $request, string $cliente): JsonResponse
    {
        Gobernanza::soloStaff($u = $request->user());
        $c = Gobernanza::cliente($cliente);
        $d = $request->validate([...self::reglasCliente(false), 'estado' => ['sometimes', 'in:activo,suspendido'], 'row_version' => ['required', 'integer']]);
        $cambios = array_diff_key($d, ['row_version' => 1]);
        if (isset($cambios['metricas'])) {
            $cambios['metricas'] = '{'.implode(',', array_unique($cambios['metricas'])).'}';
        }
        DB::transaction(function () use ($u, $c, $d, $cambios) {
            $n = DB::table('agregados_clientes')->where('id', $c->id)->where('row_version', $d['row_version'])
                ->update([...$cambios, 'row_version' => $c->row_version + 1, 'updated_at' => now()]);
            $n || throw ApiException::conflictoRowVersion(self::cliente(Gobernanza::cliente($c->id), Gobernanza::politica()));
            self::bitacora($u, $c->id, isset($cambios['estado']) && $cambios['estado'] !== $c->estado ? "licencia_{$cambios['estado']}" : 'licencia_cambio',
                ['antes' => array_intersect_key((array) $c, $cambios), 'despues' => $cambios]);
        });

        return self::json(['data' => self::cliente(Gobernanza::cliente($c->id), Gobernanza::politica())]);
    }

    public function miembros(Request $request, string $cliente): JsonResponse
    {
        Gobernanza::soloStaff($request->user());
        $c = Gobernanza::cliente($cliente);

        return self::json(['data' => DB::table('agregados_cliente_miembros as m')->join('usuarios as u', 'u.id', '=', 'm.usuario_id')
            ->where('m.cliente_id', $c->id)->where('m.estado', 'activa')->orderBy('u.nombre')->orderBy('u.id')->get(['u.id', 'u.nombre', 'u.email', 'm.created_at'])
            ->map(fn ($m) => ['usuario' => ['id' => $m->id, 'nombre' => $m->nombre, 'email' => $m->email], 'desde' => Formato::fechaHora(Carbon::parse($m->created_at))])->all()]);
    }

    public function agregarMiembro(Request $request, string $cliente): JsonResponse
    {
        Gobernanza::soloStaff($u = $request->user());
        $c = Gobernanza::cliente($cliente);
        $d = $request->validate(['email' => ['required', 'email']]);
        $m = User::query()->whereRaw('lower(email) = lower(?)', [$d['email']])->first() ?? throw ApiException::validacion(__('No hay un usuario con ese email.'), ['email' => [__('Inexistente.')]]);
        if (! Gobernanza::esMiembro($m, $c->id)) {
            DB::table('agregados_cliente_miembros')->insert(['id' => (string) Str::uuid7(), 'cliente_id' => $c->id, 'usuario_id' => $m->id, 'estado' => 'activa', 'created_at' => now(), 'updated_at' => now()]);
            self::bitacora($u, $c->id, 'integrante_alta', ['usuario_id' => $m->id, 'email' => $m->email]);
        }

        return response()->json(null, 204);
    }

    public function quitarMiembro(Request $request, string $cliente, string $usuario): JsonResponse
    {
        Gobernanza::soloStaff($u = $request->user());
        $c = Gobernanza::cliente($cliente);
        $n = DB::table('agregados_cliente_miembros')->where('cliente_id', $c->id)->where('usuario_id', strtolower($usuario))->where('estado', 'activa')
            ->update(['estado' => 'revocada', 'updated_at' => now()]);
        $n || throw ApiException::noEncontrado();
        self::bitacora($u, $c->id, 'integrante_baja', ['usuario_id' => strtolower($usuario)]);

        return response()->json(null, 204);
    }

    /** @return array<string, list<string>> */
    private static function reglasCliente(bool $alta): array
    {
        $r = $alta ? 'required' : 'sometimes';

        return ['nombre' => [$r, 'string', 'min:3', 'max:200'], 'tipo' => [$r, 'in:'.implode(',', Gobernanza::TIPOS_CLIENTE)],
            'finalidad' => [$r, 'string', 'min:20', 'max:2000'], 'contrato_version' => [$r, 'string', 'max:60'],
            'vigente_hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'], 'consultas_por_dia' => ['sometimes', 'nullable', 'integer', 'between:1,500'],
            'metricas' => [$r, 'array', 'min:1'], 'metricas.*' => ['in:'.implode(',', MotorAgregados::METRICAS)]];
    }

    /** @return array<string, mixed> */
    private static function cliente(object $c, object $politica): array
    {
        return TableroController::licencia($c, $politica) + ['consultas_por_dia' => $c->consultas_por_dia === null ? null : (int) $c->consultas_por_dia,
            'integrantes' => DB::table('agregados_cliente_miembros')->where('cliente_id', $c->id)->where('estado', 'activa')->count(),
            'consultas_30_dias' => DB::table('agregados_consultas')->where('cliente_id', $c->id)->where('created_at', '>=', now()->subDays(30))->count(),
            'row_version' => (int) $c->row_version, 'created_at' => Formato::fechaHora(Carbon::parse($c->created_at))];
    }

    /** @return array<string, mixed> */
    private static function resumenPrueba(object $p, bool $completo): array
    {
        $controles = json_decode($p->controles, true);
        $salida = ['id' => $p->id, 'resultado' => $p->resultado, 'politica_version' => (int) $p->politica_version, 'dataset_version' => $p->dataset_version,
            'fallas' => array_sum(array_column($controles, 'fallas')), 'advertencias' => array_sum(array_map(fn ($c) => $c['advertencias'] ?? 0, $controles)),
            'ejecutada_por' => $p->ejecutada_por_nombre ?? DB::table('usuarios')->where('id', $p->ejecutada_por)->value('nombre'),
            'created_at' => Formato::fechaHora(Carbon::parse($p->created_at))];

        return $completo ? $salida + ['controles' => $controles] : $salida;
    }

    /** @param array<string, mixed> $detalle */
    private static function bitacora(User $u, ?string $clienteId, string $accion, array $detalle): void
    {
        DB::table('agregados_bitacora')->insert(['usuario_id' => $u->id, 'cliente_id' => $clienteId, 'accion' => $accion,
            'detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [], JSON_UNESCAPED_UNICODE);
    }
}
