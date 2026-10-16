<?php

namespace App\Http\Controllers\Api\V1\Comprador;

use App\Casts\PgTextArray;
use App\Domain\Contratos\Actions\GestionarEntrega;
use App\Domain\Contratos\Actions\GestionarTomas;
use App\Domain\Contratos\ConsultasContratos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta liviana de comprador y toma de tokens (TPA-57). El comprador no tiene membresía en el establecimiento: toma por código
 * público (api-v1 A9) y solo ve sus tomas y su línea de la liquidación. Tomar exige perfil de comprador y email verificado.
 */
class CompradorController extends Controller
{
    public function perfil(Request $request): JsonResponse
    {
        $c = DB::table('compradores')->where('usuario_id', $request->user()->id)->first() ?? throw ApiException::noEncontrado(__('Todavía no tenés perfil de comprador.'));

        return response()->json(['data' => self::comprador($c)]);
    }

    public function guardarPerfil(Request $request): JsonResponse
    {
        $d = $request->validate(['empresa' => ['required', 'string', 'min:2', 'max:200'], 'tipo' => ['required', 'in:frigorifico,exportador,gastronomico,institucion,productor,otro'],
            'pais' => ['required', 'string', 'size:2', 'alpha'], 'idiomas' => ['sometimes', 'array'], 'idiomas.*' => ['in:es,en,pt'], 'volumen_habitual' => ['sometimes', 'nullable', 'string', 'max:200']]);
        $idiomas = '{'.implode(',', array_values(array_unique($d['idiomas'] ?? [$request->user()->idioma ?? 'es']))).'}';
        DB::table('compradores')->updateOrInsert(['usuario_id' => $request->user()->id], ['empresa' => $d['empresa'], 'tipo' => $d['tipo'], 'pais' => strtoupper($d['pais']),
            'idiomas' => $idiomas, 'volumen_habitual' => $d['volumen_habitual'] ?? null, 'updated_at' => now(), 'created_at' => now()]);

        return $this->perfil($request);
    }

    public function tomar(Request $request, string $codigo, GestionarTomas $tomas): JsonResponse
    {
        $d = $request->validate(['tokens' => ['required', 'integer', 'min:1', 'max:100000']]);
        $u = $request->user();
        DB::table('compradores')->where('usuario_id', $u->id)->exists() || throw new ApiException(403, 'perfil_comprador_requerido', __('Completá tu perfil de comprador para tomar tokens.'));
        $u->email_verificado_en || throw new ApiException(403, 'email_no_verificado', __('Verificá tu email para tomar tokens.'));
        $clave = $request->header('Idempotency-Key');
        $previa = $clave ? DB::table('tomas')->where('comprador_id', $u->id)->where('idempotency_key', $clave)->exists() : false;
        $id = $tomas->tomar($codigo, $u, (int) $d['tokens'], $clave ? mb_substr($clave, 0, 200) : null);

        return response()->json(['data' => $this->toma($id)], $previa ? 200 : 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function tomas(Request $request): JsonResponse
    {
        $estado = $request->validate(['estado' => ['sometimes', 'in:reservada,pago_informado,confirmada,liberada,devuelta_parcial,vigentes']])['estado'] ?? null;
        $p = DB::table('tomas as t')->join('contratos_futuro as c', 'c.id', '=', 't.contrato_id')->where('t.comprador_id', $request->user()->id)
            ->when($estado === 'vigentes', fn ($q) => $q->whereIn('t.estado', GestionarTomas::ACTIVAS))
            ->when($estado && $estado !== 'vigentes', fn ($q) => $q->where('t.estado', $estado))
            ->orderBy('c.fecha_entrega')->orderBy('t.id')->select('t.*')->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return response()->json(['data' => $p->getCollection()->map(fn ($t) => $this->toma($t->id))->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function pagoInformado(Request $request, string $toma, GestionarTomas $tomas): JsonResponse
    {
        $d = $request->validate(['referencia' => ['sometimes', 'nullable', 'string', 'max:200'], 'fecha_pago' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $tomas->informarPago($this->mia($request, $toma), $request->user(), $d);

        return response()->json(['data' => $this->toma($toma)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function devolucionConfirmada(Request $request, string $toma, GestionarTomas $tomas): JsonResponse
    {
        $tomas->devolucionConfirmada($this->mia($request, $toma), $request->user());

        return response()->json(['data' => $this->toma($toma)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function confirmarEntrega(Request $request, string $toma, GestionarEntrega $entregas): JsonResponse
    {
        $t = $this->mia($request, $toma);
        $c = DB::table('contratos_futuro')->where('id', $t->contrato_id)->first();
        $entregas->confirmar(Establecimiento::query()->findOrFail($c->establecimiento_id), $c, $request->user(), 'comprador');

        return response()->json(['data' => ConsultasContratos::entrega(DB::table('entregas')->where('contrato_id', $c->id)->first(), false, $request->user()->id)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> Toma + `contrato` (aditivo: código, establecimiento, entrega, estado) para «Mis contratos» */
    private function toma(string $id): array
    {
        $t = DB::table('tomas')->where('id', $id)->first();
        $c = DB::table('contratos_futuro as c')->join('establecimientos as e', 'e.id', '=', 'c.establecimiento_id')->where('c.id', $t->contrato_id)
            ->first(['c.codigo_publico', 'c.fecha_entrega', 'c.estado', 'c.especie_codigo', 'c.categoria_codigo', 'c.moneda', 'e.nombre']);
        $e = DB::table('entregas')->where('contrato_id', $t->contrato_id)->first();

        return [...ConsultasContratos::toma($t), 'contrato' => ['codigo' => $c->codigo_publico, 'establecimiento' => $c->nombre, 'especie' => $c->especie_codigo,
            'categoria' => $c->categoria_codigo, 'fecha_entrega' => Carbon::parse($c->fecha_entrega)->toDateString(), 'estado' => $c->estado, 'moneda' => $c->moneda],
            'entrega' => $e ? ConsultasContratos::entrega($e, false, $t->comprador_id) : null];
    }

    private function mia(Request $request, string $toma): object
    {
        return DB::table('tomas')->where('id', strtolower($toma))->where('comprador_id', $request->user()->id)->first() ?? throw ApiException::noEncontrado();
    }

    /** @return array<string, mixed> */
    public static function comprador(object $c): array
    {
        return ['empresa' => $c->empresa, 'tipo' => $c->tipo, 'pais' => $c->pais, 'idiomas' => PgTextArray::parse($c->idiomas), 'volumen_habitual' => $c->volumen_habitual];
    }

    // ---- Seguir productores ----

    public function seguimientos(Request $request): JsonResponse
    {
        $s = DB::table('seguimientos as s')->join('establecimientos as e', 'e.id', '=', 's.establecimiento_id')
            ->leftJoin('perfiles_publicos as p', 'p.establecimiento_id', '=', 'e.id')->where('s.usuario_id', $request->user()->id)->whereNull('s.dejado_en')
            ->orderByDesc('s.created_at')->get(['e.id', 'e.nombre', 'p.slug', 's.created_at']);

        return response()->json(['data' => $s->map(fn ($x) => ['establecimiento' => ['id' => $x->id, 'slug' => (string) $x->slug, 'nombre' => $x->nombre],
            'desde' => Formato::fechaHora(Carbon::parse($x->created_at))])->all()], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function seguir(Request $request): JsonResponse
    {
        $slug = $request->validate(['establecimiento_slug' => ['required', 'string', 'max:60']])['establecimiento_slug'];
        $p = DB::table('perfiles_publicos')->where('slug', $slug)->where('publicado', true)->first() ?? throw ApiException::noEncontrado();
        DB::table('seguimientos')->updateOrInsert(['usuario_id' => $request->user()->id, 'establecimiento_id' => $p->establecimiento_id], ['created_at' => now(), 'dejado_en' => null]);
        $nombre = DB::table('establecimientos')->where('id', $p->establecimiento_id)->value('nombre');

        return response()->json(['data' => ['establecimiento' => ['id' => $p->establecimiento_id, 'slug' => $slug, 'nombre' => $nombre], 'desde' => Formato::fechaHora(now())]], 201, [], JSON_UNESCAPED_UNICODE);
    }

    /** Dejar de seguir (api-v1 A8: no es evidencia; se marca `dejado_en`). */
    public function dejar(Request $request, string $slug): JsonResponse
    {
        $estId = DB::table('perfiles_publicos')->where('slug', $slug)->value('establecimiento_id') ?? throw ApiException::noEncontrado();
        DB::table('seguimientos')->where('usuario_id', $request->user()->id)->where('establecimiento_id', $estId)->update(['dejado_en' => now()]);

        return response()->json(null, 204);
    }
}
