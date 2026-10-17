<?php

namespace App\Http\Controllers\Api\V1\Agregados;

use App\Domain\Agregados\DatasetSintetico;
use App\Domain\Agregados\Gobernanza;
use App\Domain\Agregados\MotorAgregados;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tablero agregado para clientes institucionales (TPA-75): contexto de licencias, consulta del cubo protegido, export CSV y
 * consultas auditadas. Fuente única: dataset sintético. Cada consulta descuenta cupo diario y queda registrada (append-only).
 */
final class TableroController extends Controller
{
    public const AVISO = 'Prototipo con datos sintéticos: no representan establecimientos reales. Ningún dato de productores se usa hasta que el gate de gobernanza y la revisión legal habiliten el opt-in.';

    public function contexto(Request $request, DatasetSintetico $dataset): JsonResponse
    {
        $u = $request->user();
        $dataset->asegurar();
        $politica = Gobernanza::politica();
        $licencias = DB::table('agregados_clientes as c')->join('agregados_cliente_miembros as m', 'm.cliente_id', '=', 'c.id')
            ->where('m.usuario_id', $u->id)->where('m.estado', 'activa')->orderBy('c.nombre')->orderBy('c.id')->get(['c.*'])
            ->map(fn ($c) => self::licencia($c, $politica))->all();

        return self::json(['data' => [
            'acceso' => $u->es_staff || $licencias !== [], 'es_staff' => (bool) $u->es_staff, 'licencias' => $licencias,
            'politica' => self::politica($politica), 'trimestres' => DatasetSintetico::TRIMESTRES, 'metricas' => MotorAgregados::METRICAS,
            'especies' => MotorAgregados::ESPECIES, 'zonas' => self::zonas(), 'escalas' => MotorAgregados::ESCALAS,
            'fuente' => 'sintetico', 'dataset_version' => DatasetSintetico::VERSION,
            'opt_in_real_habilitado' => Gobernanza::gate($politica)['habilita_opt_in_real'], 'aviso' => __(self::AVISO),
        ]]);
    }

    public function tablero(Request $request, DatasetSintetico $dataset, MotorAgregados $motor): JsonResponse
    {
        [$d, $cliente, $politica, $resultado, $consulta] = $this->consultar($request, $dataset, $motor, 'tablero');

        return self::json(['data' => [
            'metrica' => $d['metrica'], 'especie' => $d['especie'], 'geo' => $d['geo'], 'desglose_escala' => $d['escala'],
            'trimestres' => $d['trimestres'], 'filas' => $resultado['filas'], 'resumen' => $resultado['resumen'],
            'consulta' => $consulta, 'politica' => self::politica($politica), 'fuente' => 'sintetico', 'aviso' => __(self::AVISO),
        ]]);
    }

    public function exportar(Request $request, DatasetSintetico $dataset, MotorAgregados $motor): Response
    {
        [$d, $cliente, $politica, $resultado, $consulta] = $this->consultar($request, $dataset, $motor, 'exportacion');
        $valor = ['existencias' => 'cabezas', 'cobertura_sanitaria' => 'cobertura_pct', 'precios' => 'precio_kg'][$d['metrica']];
        $out = fopen('php://temp', 'w+');
        fwrite($out, '# TROPILLA · '.__(self::AVISO).' Dataset '.DatasetSintetico::VERSION.' · política v'.$politica->version.' · consulta '.$consulta['id']."\n");
        fputcsv($out, ['trimestre', 'zona', 'escala', 'estado', 'motivo', 'establecimientos', $valor], escape: '');
        foreach ($resultado['filas'] as $f) {
            fputcsv($out, [$f['trimestre'], $f['zona']['codigo'], $f['escala'], $f['estado'], $f['motivo'] ?? '',
                $f['valores']['establecimientos'] ?? '', $f['valores'][$valor] ?? ''], escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $nombre = "tropilla-agregados-sinteticos-{$d['metrica']}-{$d['especie']}-{$d['trimestres'][0]}-".end($d['trimestres']).'.csv';

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$nombre}\"",
            'X-Tropilla-Fuente' => 'sintetico', 'Cache-Control' => 'no-store']);
    }

    /** Consultas auditadas: el equipo ve todas; un cliente, solo las de su licencia. */
    public function consultas(Request $request): JsonResponse
    {
        $u = $request->user();
        $clienteId = $request->query('cliente');
        if (! $u->es_staff) {
            is_string($clienteId) || throw ApiException::noEncontrado();
            Gobernanza::esMiembro($u, Gobernanza::cliente($clienteId)->id) || throw ApiException::noEncontrado();
        }
        $q = DB::table('agregados_consultas as q')->join('usuarios as u', 'u.id', '=', 'q.usuario_id')->leftJoin('agregados_clientes as c', 'c.id', '=', 'q.cliente_id')
            ->select('q.*', 'u.nombre as usuario_nombre', 'c.nombre as cliente_nombre')->orderByDesc('q.created_at')->orderByDesc('q.id');
        if (is_string($clienteId)) {
            $q->where('q.cliente_id', Gobernanza::cliente($clienteId)->id);
        }
        $p = $q->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return self::json(['data' => $p->getCollection()->map(fn ($c) => [
            'id' => $c->id, 'tipo' => $c->tipo, 'cliente' => $c->cliente_id ? ['id' => $c->cliente_id, 'nombre' => $c->cliente_nombre] : null,
            'usuario' => ['id' => $c->usuario_id, 'nombre' => $c->usuario_nombre], 'parametros' => json_decode($c->parametros, true),
            'celdas' => (int) $c->celdas, 'suprimidas' => (int) $c->suprimidas, 'politica_version' => (int) $c->politica_version,
            'dataset_version' => $c->dataset_version, 'fuente' => $c->fuente, 'created_at' => Formato::fechaHora(Carbon::parse($c->created_at)),
        ])->all(), 'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]]);
    }

    /** @return array{0: array<string, mixed>, 1: ?object, 2: object, 3: array<string, mixed>, 4: array<string, mixed>} */
    private function consultar(Request $request, DatasetSintetico $dataset, MotorAgregados $motor, string $tipo): array
    {
        $d = $request->validate([
            'cliente' => ['sometimes', 'nullable', 'uuid'], 'metrica' => ['required', 'in:'.implode(',', MotorAgregados::METRICAS)],
            'especie' => ['required', 'in:'.implode(',', MotorAgregados::ESPECIES)], 'geo' => ['sometimes', 'in:nea,provincia'],
            'escala' => ['sometimes', 'boolean'], 'desde' => ['sometimes', 'in:'.implode(',', DatasetSintetico::TRIMESTRES)],
            'hasta' => ['sometimes', 'in:'.implode(',', DatasetSintetico::TRIMESTRES)],
        ]);
        /** @var User $u */
        $u = $request->user();
        $cliente = Gobernanza::licencia($u, $d['cliente'] ?? null, $d['metrica']);
        $politica = Gobernanza::politica();
        $d['geo'] ??= 'provincia';
        $d['escala'] = filter_var($d['escala'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($d['escala'] && ! $politica->desglose_escala) {
            throw new ApiException(422, 'desglose_no_permitido', __('La política vigente no permite desglosar por escala.'));
        }
        $todos = DatasetSintetico::TRIMESTRES;
        $desde = array_search($d['desde'] ?? $todos[count($todos) - 4], $todos, true);
        $hasta = array_search($d['hasta'] ?? end($todos), $todos, true);
        $desde <= $hasta || throw ApiException::validacion(__('El período es inválido.'), ['desde' => [__('Tiene que ser anterior o igual a «hasta».')]]);
        $d['trimestres'] = array_slice($todos, $desde, $hasta - $desde + 1);
        $dataset->asegurar();

        return DB::transaction(function () use ($request, $motor, $u, $cliente, $politica, $d, $tipo) {
            $cupo = null;
            if ($cliente !== null) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['agregados|'.$cliente->id]);
                $cupo = Gobernanza::cupoDiario($cliente, $politica);
                Gobernanza::consultasHoy($cliente->id) < $cupo
                    || throw new ApiException(429, 'cupo_consultas_agotado', __('La licencia usó sus :n consultas de hoy. El cupo se renueva a la medianoche.', ['n' => $cupo]));
            }
            $resultado = $this->filas($motor, $politica, $d);
            $id = (string) Str::uuid7();
            DB::table('agregados_consultas')->insert([
                'id' => $id, 'cliente_id' => $cliente?->id, 'usuario_id' => $u->id, 'tipo' => $tipo,
                'parametros' => json_encode(['metrica' => $d['metrica'], 'especie' => $d['especie'], 'geo' => $d['geo'], 'escala' => $d['escala'],
                    'desde' => $d['trimestres'][0], 'hasta' => end($d['trimestres'])]),
                'celdas' => $resultado['resumen']['celdas'], 'suprimidas' => $resultado['resumen']['suprimidas'], 'politica_version' => $politica->version,
                'dataset_version' => DatasetSintetico::VERSION, 'fuente' => 'sintetico',
                'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), (string) config('app.key')) : null, 'created_at' => now(),
            ]);
            $consulta = ['id' => $id, 'cliente_id' => $cliente?->id, 'politica_version' => (int) $politica->version, 'dataset_version' => DatasetSintetico::VERSION,
                'cupo_diario' => $cupo, 'cupo_restante' => $cupo === null ? null : max(0, $cupo - Gobernanza::consultasHoy($cliente->id))];

            return [$d, $cliente, $politica, $resultado, $consulta];
        });
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{filas: list<array<string, mixed>>, resumen: array{celdas: int, suprimidas: int}}
     */
    private function filas(MotorAgregados $motor, object $politica, array $d): array
    {
        $zonas = $d['geo'] === 'nea' ? ['nea'] : ['nea', ...array_keys(MotorAgregados::PROVINCIAS)];
        $escalas = $d['escala'] ? ['todas', ...MotorAgregados::ESCALAS] : ['todas'];
        $nombres = collect(self::zonas())->pluck('nombre', 'codigo');
        $filas = [];
        $suprimidas = 0;
        foreach ($d['trimestres'] as $t) {
            $cubo = $motor->cubo($d['metrica'], $d['especie'], $t, $politica);
            foreach ($zonas as $z) {
                foreach ($escalas as $e) {
                    $c = $cubo["{$z}|{$e}"];
                    $estado = $c['n'] === 0 ? 'sin_datos' : ($c['motivo'] === null ? 'publicada' : 'suprimida');
                    $suprimidas += $estado === 'suprimida' ? 1 : 0;
                    $filas[] = ['trimestre' => $t, 'zona' => ['codigo' => $z, 'nombre' => $nombres[$z]], 'escala' => $e, 'estado' => $estado,
                        'motivo' => $estado === 'suprimida' ? $c['motivo'] : null, 'valores' => MotorAgregados::valores($d['metrica'], $c, $politica)];
                }
            }
        }

        return ['filas' => $filas, 'resumen' => ['celdas' => count($filas), 'suprimidas' => $suprimidas]];
    }

    /** @return list<array{codigo: string, nombre: string}> */
    private static function zonas(): array
    {
        return [['codigo' => 'nea', 'nombre' => 'NEA'], ...array_map(fn ($c, $n) => ['codigo' => $c, 'nombre' => $n], array_keys(MotorAgregados::PROVINCIAS), MotorAgregados::PROVINCIAS)];
    }

    /** @return array<string, mixed> */
    public static function politica(object $p): array
    {
        return ['version' => (int) $p->version, 'k_minimo' => (int) $p->k_minimo, 'dominancia_max_pct' => (int) $p->dominancia_max_pct,
            'redondeo_cabezas' => (int) $p->redondeo_cabezas, 'consultas_por_dia' => (int) $p->consultas_por_dia, 'desglose_escala' => (bool) $p->desglose_escala,
            'nota' => $p->nota, 'created_at' => Formato::fechaHora(Carbon::parse($p->created_at))];
    }

    /** @return array<string, mixed> */
    public static function licencia(object $c, object $politica): array
    {
        return ['id' => $c->id, 'nombre' => $c->nombre, 'tipo' => $c->tipo, 'finalidad' => $c->finalidad, 'contrato_version' => $c->contrato_version,
            'vigente_hasta' => $c->vigente_hasta, 'estado' => $c->estado, 'metricas' => Gobernanza::metricas($c),
            'cupo_diario' => Gobernanza::cupoDiario($c, $politica), 'consultas_hoy' => Gobernanza::consultasHoy($c->id)];
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [], JSON_UNESCAPED_UNICODE);
    }
}
