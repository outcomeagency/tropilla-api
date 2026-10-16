<?php

namespace App\Http\Controllers\Api\V1\Contratos;

use App\Domain\Contratos\Actions\GestionarContrato;
use App\Domain\Contratos\Actions\GestionarEntrega;
use App\Domain\Contratos\Actions\GestionarTomas;
use App\Domain\Contratos\ConsultasContratos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contratos\ContratoRequest;
use App\Http\Requests\V1\MotivoRequest;
use App\Rules\Reglas;
use App\Support\Decimales as D;
use App\Support\Formato;
use App\Support\Leyenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Contratos a futuro con token — lado productor (TPA-56). */
class ContratoController extends Controller
{
    public function calcular(ContratoRequest $request, Establecimiento $establecimiento, GestionarContrato $g): JsonResponse
    {
        return response()->json(['data' => $g->calcular($establecimiento, $request->validated())]);
    }

    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('contratos.ver', $establecimiento);
        $estado = $request->validate(['estado' => ['sometimes', 'in:borrador,publicado,parcialmente_tomado,completo,entregado,liquidado,cancelado,incumplido']])['estado'] ?? null;
        $p = DB::table('contratos_futuro')->where('establecimiento_id', $establecimiento->id)->when($estado, fn ($q, $v) => $q->where('estado', $v))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');
        $montos = Gate::allows('comercial.precios', $establecimiento);

        return response()->json(['data' => $p->getCollection()->map(fn ($c) => ConsultasContratos::contrato($c, $montos))->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(ContratoRequest $request, Establecimiento $establecimiento, GestionarContrato $g): JsonResponse
    {
        return $this->res($establecimiento, $g->crear($establecimiento, $request->user(), $request->validated()), 201);
    }

    public function show(Establecimiento $establecimiento, string $contrato): JsonResponse
    {
        Gate::authorize('contratos.ver', $establecimiento);

        return $this->res($establecimiento, $this->buscar($establecimiento, $contrato)->id);
    }

    public function preview(Request $request, Establecimiento $establecimiento, string $contrato): JsonResponse
    {
        Gate::authorize('contratos.gestionar', $establecimiento);

        return response()->json(['data' => ConsultasContratos::publico($this->buscar($establecimiento, $contrato), true, Leyenda::locale($request->query('locale')))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function publicar(Request $request, Establecimiento $establecimiento, string $contrato, GestionarContrato $g): JsonResponse
    {
        Gate::authorize('contratos.gestionar', $establecimiento);
        $g->publicar($establecimiento, $request->user(), $this->buscar($establecimiento, $contrato));

        return $this->res($establecimiento, $contrato);
    }

    public function terminos(ContratoRequest $request, Establecimiento $establecimiento, string $contrato, GestionarContrato $g): JsonResponse
    {
        $c = $this->buscar($establecimiento, $contrato);
        $sim = $g->terminos($establecimiento, $request->user(), $c, $request->validated(), $request->boolean('simular'));

        return $sim !== null ? response()->json(['data' => $sim]) : $this->res($establecimiento, $c->id, 201);
    }

    public function cancelar(MotivoRequest $request, Establecimiento $establecimiento, string $contrato, GestionarContrato $g): JsonResponse
    {
        Gate::authorize('contratos.gestionar', $establecimiento);
        $g->cancelar($establecimiento, $request->user(), $this->buscar($establecimiento, $contrato), $request->validated('motivo'));

        return $this->res($establecimiento, $contrato);
    }

    public function incumplido(MotivoRequest $request, Establecimiento $establecimiento, string $contrato, GestionarContrato $g): JsonResponse
    {
        Gate::authorize('contratos.gestionar', $establecimiento);
        $g->incumplido($establecimiento, $request->user(), $this->buscar($establecimiento, $contrato), $request->validated('motivo'));

        return $this->res($establecimiento, $contrato);
    }

    public function confirmarPago(Request $request, Establecimiento $establecimiento, string $contrato, string $toma, GestionarTomas $g): JsonResponse
    {
        Gate::authorize('contratos.gestionar', $establecimiento);
        $t = $this->toma($establecimiento, $contrato, $toma);
        $g->confirmarPago($t, $request->user());

        return response()->json(['data' => ConsultasContratos::toma(DB::table('tomas')->where('id', $t->id)->first())]);
    }

    public function devolucionInformada(Request $request, Establecimiento $establecimiento, string $contrato, string $toma, GestionarTomas $g): JsonResponse
    {
        Gate::authorize('contratos.gestionar', $establecimiento);
        $d = $request->validate(['referencia' => ['sometimes', 'nullable', 'string', 'max:200'], 'fecha_pago' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $t = $this->toma($establecimiento, $contrato, $toma);
        $g->devolucionInformada($t, $request->user(), $d);

        return response()->json(['data' => ConsultasContratos::toma(DB::table('tomas')->where('id', $t->id)->first())]);
    }

    public function entrega(Request $request, Establecimiento $establecimiento, string $contrato, GestionarEntrega $g): JsonResponse
    {
        Gate::authorize('contratos.gestionar', $establecimiento);
        $d = $request->validate(['cabezas_entregadas' => ['required', 'integer', 'min:0', 'max:100000'], 'peso_vivo_total_kg' => ['required', 'string', Reglas::DECIMAL_POS, 'numeric', 'gt:0'],
            'rendimiento_real_pct' => ['required', 'string', Reglas::DECIMAL_POS, 'numeric', 'gt:0', 'max:100'], 'kg_gancho_reales' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $c = $this->buscar($establecimiento, $contrato);
        $g->cargar($establecimiento, $c, $d);

        return response()->json(['data' => ConsultasContratos::entrega(DB::table('entregas')->where('contrato_id', $c->id)->first(), true)]);
    }

    public function confirmarEntrega(Request $request, Establecimiento $establecimiento, string $contrato, GestionarEntrega $g): JsonResponse
    {
        Gate::authorize('contratos.gestionar', $establecimiento);
        $c = $this->buscar($establecimiento, $contrato);
        $g->confirmar($establecimiento, $c, $request->user(), 'productor');

        return response()->json(['data' => ConsultasContratos::entrega(DB::table('entregas')->where('contrato_id', $c->id)->first(), true)]);
    }

    /** Panel «Mi apalancamiento»: anticipos cobrados/pendientes, kg comprometidos y avance de cada contrato abierto. */
    public function apalancamiento(Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('comercial.precios', $establecimiento);
        $tomas = DB::table('tomas as t')->join('contratos_futuro as c', 'c.id', '=', 't.contrato_id')->where('c.establecimiento_id', $establecimiento->id)
            ->whereNotIn('c.estado', ['cancelado', 'incumplido', 'liquidado'])->get(['t.*', 'c.kg_por_token']);
        $cobrados = D::sum('0', ...$tomas->whereIn('estado', GestionarTomas::CONFIRMADAS)->map(fn ($t) => D::sub((string) $t->anticipo_monto, (string) $t->anticipo_a_devolver))->all());
        $pendientes = D::sum('0', ...$tomas->whereIn('estado', ['reservada', 'pago_informado'])->pluck('anticipo_monto')->map(fn ($x) => (string) $x)->all());
        $contratos = DB::table('contratos_futuro')->where('establecimiento_id', $establecimiento->id)->whereNotIn('estado', ['borrador', 'cancelado', 'liquidado', 'incumplido'])
            ->orderBy('fecha_entrega')->get();

        return response()->json(['data' => [
            'anticipos_cobrados' => D::redondear($cobrados, 2), 'anticipos_pendientes' => D::redondear($pendientes, 2),
            'kg_comprometidos' => (int) $tomas->whereIn('estado', GestionarTomas::CONFIRMADAS)->sum(fn ($t) => $t->tokens_vigentes * $t->kg_por_token),
            'moneda' => (string) ($contratos->first()->moneda ?? 'ARS'),
            'contratos' => $contratos->map(fn ($c) => ['id' => $c->id, 'lote' => (string) DB::table('lotes')->where('id', $c->lote_id)->value('nombre'),
                'pct_tomado' => Formato::decimal(D::redondear(D::mul(D::div((string) $c->tokens_comprometidos, (string) $c->tokens_totales), '100'), 2)),
                'fecha_entrega' => (string) $c->fecha_entrega, 'estado' => $c->estado])->all(),
        ]]);
    }

    private function res(Establecimiento $est, string $id, int $status = 200): JsonResponse
    {
        return response()->json(['data' => ConsultasContratos::contrato(DB::table('contratos_futuro')->where('id', $id)->first(), Gate::allows('comercial.precios', $est))],
            $status, [], JSON_UNESCAPED_UNICODE);
    }

    private function buscar(Establecimiento $est, string $id): object
    {
        return DB::table('contratos_futuro')->where('establecimiento_id', $est->id)->where('id', strtolower($id))->first() ?? throw ApiException::noEncontrado();
    }

    private function toma(Establecimiento $est, string $contrato, string $toma): object
    {
        $c = $this->buscar($est, $contrato);

        return DB::table('tomas')->where('contrato_id', $c->id)->where('id', strtolower($toma))->first() ?? throw ApiException::noEncontrado();
    }
}
