<?php

namespace App\Http\Controllers\Api\V1\Cuenta;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Notificaciones in-app y preferencias (TPA-53). Siempre las del usuario autenticado. */
class NotificacionController extends Controller
{
    public const TIPOS = ['tarea_sanitaria', 'refuerzo', 'documento_vence', 'parto_proximo', 'parto_vencido', 'servicio_apta', 'peso_primer_servicio',
        'recategorizacion', 'retiro_en_venta', 'consulta_nueva', 'toma_nueva', 'pago_informado', 'entrega_proxima', 'busco_coincide', 'sync_pendiente',
        'toma_liberada', 'recorte_prorrata', 'revision_abierta', 'contrato_nuevo', 'oferta_nueva', 'busco_respuesta', 'entrega_liquidada'];

    public function index(Request $request): JsonResponse
    {
        $p = DB::table('notificaciones')->where('usuario_id', $request->user()->id)
            ->when($request->boolean('no_leidas'), fn ($q) => $q->whereNull('leida_en'))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return response()->json(['data' => $p->getCollection()->map(fn ($n) => self::notificacion($n))->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage(),
                'no_leidas' => DB::table('notificaciones')->where('usuario_id', $request->user()->id)->whereNull('leida_en')->count()]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function leer(Request $request, string $notificacion): JsonResponse
    {
        $n = DB::table('notificaciones')->where('id', strtolower($notificacion))->where('usuario_id', $request->user()->id)->first() ?? throw ApiException::noEncontrado();
        if ($n->leida_en === null) {
            DB::table('notificaciones')->where('id', $n->id)->update(['leida_en' => now()]);
        }

        return response()->json(null, 204);
    }

    public function preferencias(Request $request): JsonResponse
    {
        $prefs = DB::table('preferencias_notificacion')->where('usuario_id', $request->user()->id)->orderBy('tipo')->get(['establecimiento_id', 'tipo', 'push', 'email', 'resumen_diario']);

        return response()->json(['data' => $prefs->map(fn ($p) => ['establecimiento_id' => $p->establecimiento_id, 'tipo' => $p->tipo, 'push' => (bool) $p->push,
            'email' => (bool) $p->email, 'resumen_diario' => (bool) $p->resumen_diario])->all(), 'meta' => ['defaults' => ['push' => true, 'email' => false, 'resumen_diario' => false]]]);
    }

    public function guardarPreferencias(Request $request): JsonResponse
    {
        $items = $request->validate(['*' => ['array'], '*.establecimiento_id' => ['sometimes', 'nullable', 'uuid'], '*.tipo' => ['required', Rule::in(self::TIPOS)],
            '*.push' => ['required', 'boolean'], '*.email' => ['required', 'boolean'], '*.resumen_diario' => ['required', 'boolean']]);
        if (! array_is_list($request->all()) || $request->all() === []) {
            throw ApiException::validacion(__('Se espera una lista de preferencias.'), ['preferencias' => [__('Lista no vacía.')]]);
        }
        $u = $request->user();
        foreach ($items as $i => $p) {
            if (! empty($p['establecimiento_id']) && ! DB::table('membresias')->where('usuario_id', $u->id)->where('establecimiento_id', $p['establecimiento_id'])->where('estado', 'activa')->exists()) {
                throw ApiException::noEncontrado();
            }
        }
        DB::transaction(function () use ($items, $u) {
            foreach ($items as $p) {
                DB::table('preferencias_notificacion')->updateOrInsert(
                    ['usuario_id' => $u->id, 'establecimiento_id' => $p['establecimiento_id'] ?? null, 'tipo' => $p['tipo']],
                    ['push' => $p['push'], 'email' => $p['email'], 'resumen_diario' => $p['resumen_diario'], 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> Notificacion */
    public static function notificacion(object $n): array
    {
        return ['id' => $n->id, 'tipo' => $n->tipo, 'establecimiento_id' => $n->establecimiento_id, 'titulo' => $n->titulo, 'cuerpo' => $n->cuerpo,
            'datos' => (object) json_decode($n->datos, true), 'leida_en' => $n->leida_en ? Formato::fechaHora(Carbon::parse($n->leida_en)) : null,
            'created_at' => Formato::fechaHora(Carbon::parse($n->created_at))];
    }
}
