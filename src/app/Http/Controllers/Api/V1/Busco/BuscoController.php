<?php

namespace App\Http\Controllers\Api\V1\Busco;

use App\Domain\Busco\Buscos;
use App\Domain\Busco\Coincidencias;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Notificaciones\Notificador;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Rules\Reglas;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** «Busco» (TPA-58): el comprador publica lo que busca; al productor le aparece en «Demandas que calzan con tu hacienda» y responde adentro. */
class BuscoController extends Controller
{
    // ---- Comprador ----

    public function index(Request $request): JsonResponse
    {
        $p = DB::table('buscos')->where('comprador_id', $request->user()->id)->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return $this->pagina($p, fn ($b) => Buscos::busco($b, true));
    }

    public function store(Request $request, Coincidencias $coincidencias): JsonResponse
    {
        $u = $request->user();
        DB::table('compradores')->where('usuario_id', $u->id)->exists() || throw new ApiException(403, 'perfil_comprador_requerido', __('Completá tu perfil de comprador para publicar un Busco.'));
        $u->email_verificado_en || throw new ApiException(403, 'email_no_verificado', __('Verificá tu email para publicar.'));
        $d = $this->validar($request, false);
        $id = (string) Str::uuid7();
        DB::table('buscos')->insert(['id' => $id, 'comprador_id' => $u->id, ...$this->fila($d), 'estado' => 'abierta', 'created_at' => now(), 'updated_at' => now()]);
        $coincidencias->calcular(DB::table('buscos')->where('id', $id)->first());

        return response()->json(['data' => Buscos::busco(DB::table('buscos')->where('id', $id)->first(), true)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(Request $request, string $busco, Coincidencias $coincidencias): JsonResponse
    {
        $b = $this->mio($request, $busco);
        if ($b->estado !== 'abierta') {
            throw ApiException::reglaBloqueante(__('El Busco está :e.', ['e' => $b->estado]));
        }
        DB::table('buscos')->where('id', $b->id)->update([...$this->fila($this->validar($request, true)), 'updated_at' => now()]);
        $coincidencias->calcular(DB::table('buscos')->where('id', $b->id)->first());

        return response()->json(['data' => Buscos::busco(DB::table('buscos')->where('id', $b->id)->first(), true)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function cerrar(Request $request, string $busco, Coincidencias $coincidencias): JsonResponse
    {
        $b = $this->mio($request, $busco);
        DB::table('buscos')->where('id', $b->id)->whereIn('estado', ['abierta'])->update(['estado' => 'cerrada', 'cerrada_en' => now(), 'updated_at' => now()]);
        $coincidencias->calcular(DB::table('buscos')->where('id', $b->id)->first());

        return response()->json(['data' => Buscos::busco(DB::table('buscos')->where('id', $b->id)->first(), true)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function respuestas(Request $request, string $busco): JsonResponse
    {
        $b = $this->mio($request, $busco);
        $rs = DB::table('busco_respuestas')->where('busco_id', $b->id)->orderByDesc('created_at')->get();
        DB::table('busco_respuestas')->where('busco_id', $b->id)->whereNull('leida_en')->update(['leida_en' => now()]);

        return response()->json(['data' => $rs->map(fn ($r) => Buscos::respuesta($r))->all()], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ---- Productor ----

    public function demandas(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('busco.ver_coincidencias', $establecimiento);
        $p = DB::table('busco_coincidencias as c')->join('buscos as b', 'b.id', '=', 'c.busco_id')->where('c.establecimiento_id', $establecimiento->id)
            ->where('b.estado', 'abierta')->orderByDesc('c.puntaje')->orderByDesc('b.created_at')->orderBy('b.id')
            ->select('b.*', 'c.puntaje', 'c.motivo')->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return $this->pagina($p, fn ($x) => ['busco' => Buscos::busco($x), 'puntaje' => (int) $x->puntaje, 'motivo' => (object) json_decode($x->motivo, true),
            'respondida' => DB::table('busco_respuestas')->where('busco_id', $x->id)->where('establecimiento_id', $establecimiento->id)->exists()]);
    }

    public function responder(Request $request, Establecimiento $establecimiento, string $busco): JsonResponse
    {
        Gate::authorize('busco.responder', $establecimiento);
        $d = $request->validate(['mensaje' => ['required', 'string', 'min:3', 'max:2000'],
            'oferta_id' => ['sometimes', 'nullable', Reglas::UUID, Rule::exists('ofertas', 'id')->where('establecimiento_id', $establecimiento->id)->whereIn('estado', ['publicada', 'reservada'])]]);
        $b = DB::table('buscos')->where('id', strtolower($busco))->where('estado', 'abierta')->first() ?? throw ApiException::noEncontrado();
        DB::table('busco_coincidencias')->where('busco_id', $b->id)->where('establecimiento_id', $establecimiento->id)->exists() || throw ApiException::noEncontrado();
        $id = (string) Str::uuid7();
        DB::table('busco_respuestas')->insert(['id' => $id, 'busco_id' => $b->id, 'establecimiento_id' => $establecimiento->id, 'autor_id' => $request->user()->id,
            'mensaje' => $d['mensaje'], 'oferta_id' => $d['oferta_id'] ?? null, 'created_at' => now()]);
        // El comprador recibe la respuesta sin exponer su contacto (resuelve la duda 22: mensajería mínima interna).
        app(Notificador::class)->aUsuario($b->comprador_id, null, 'busco_respuesta', "{$establecimiento->nombre} respondió tu Busco", mb_substr($d['mensaje'], 0, 140),
            ['busco_id' => $b->id, 'respuesta_id' => $id], "busco_respuesta:{$id}");

        return response()->json(['data' => Buscos::respuesta(DB::table('busco_respuestas')->where('id', $id)->first())], 201, [], JSON_UNESCAPED_UNICODE);
    }

    // ---- Público ----

    public function publicos(Request $request): JsonResponse
    {
        $f = $request->validate(['especie' => ['sometimes', 'exists:especies,codigo'], 'provincia' => ['sometimes', 'string', 'max:2']]);
        $p = DB::table('buscos')->where('estado', 'abierta')->where(fn ($q) => $q->whereNull('plazo_hasta')->orWhere('plazo_hasta', '>=', now()->toDateString()))
            ->when($f['especie'] ?? null, fn ($q, $v) => $q->where('especie_codigo', $v))
            ->when($f['provincia'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->whereRaw("(zona->>'cualquiera')::boolean IS TRUE")->orWhereRaw("jsonb_exists(zona->'provincias', ?)", [$v])))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return $this->pagina($p, fn ($b) => Buscos::busco($b));
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, bool $parcial): array
    {
        $req = $parcial ? 'sometimes' : 'required';
        $especie = $request->input('especie');

        return $request->validate([
            'especie' => [$req, 'exists:especies,codigo'],
            'categorias' => [$req, 'array', 'min:1', 'max:20'], 'categorias.*' => [Rule::exists('categorias', 'codigo')->when($especie, fn ($r) => $r->where('especie_codigo', $especie))],
            'razas' => ['sometimes', 'array', 'max:20'], 'razas.*' => [Rule::exists('razas', 'codigo')->whereNull('establecimiento_id')],
            'cantidad' => [$req, 'integer', 'min:1', 'max:100000'],
            'peso_objetivo_kg' => ['sometimes', 'nullable', 'string', Reglas::DECIMAL_POS, 'numeric', 'max:2000'],
            'zona' => [$req, 'array'], 'zona.cualquiera' => ['sometimes', 'boolean'], 'zona.provincias' => ['sometimes', 'array'], 'zona.provincias.*' => ['string', 'size:2'],
            'zona.departamentos' => ['sometimes', 'array'], 'zona.departamentos.*' => ['string', 'max:5'],
            'plazo_hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'mensaje' => ['sometimes', 'nullable', 'string', 'max:2000'], 'mostrar_nombre' => ['sometimes', 'boolean'],
        ]);
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    private function fila(array $d): array
    {
        $f = [];
        foreach (['cantidad', 'peso_objetivo_kg', 'plazo_hasta', 'mensaje', 'mostrar_nombre'] as $k) {
            if (array_key_exists($k, $d)) {
                $f[$k] = $d[$k];
            }
        }
        if (isset($d['especie'])) {
            $f['especie_codigo'] = $d['especie'];
        }
        foreach (['categorias', 'razas'] as $k) {
            if (isset($d[$k])) {
                $f[$k] = '{'.implode(',', array_values(array_unique($d[$k]))).'}';
            }
        }
        if (isset($d['zona'])) {
            $f['zona'] = json_encode((object) $d['zona']);
        }

        return $f;
    }

    private function mio(Request $request, string $id): object
    {
        return DB::table('buscos')->where('id', strtolower($id))->where('comprador_id', $request->user()->id)->first() ?? throw ApiException::noEncontrado();
    }

    private function pagina(CursorPaginator $p, \Closure $map): JsonResponse
    {
        return response()->json(['data' => collect($p->items())->map($map)->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
