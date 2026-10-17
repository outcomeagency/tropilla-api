<?php

namespace App\Http\Controllers\Api\V1\Proveedores;

use App\Domain\Contrapartes\Fuentes\BcraCentralDeudores;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Notificaciones\Notificador;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CursorRespuesta;
use App\Rules\Cuit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Directorio del ecosistema (TPA-82). Los datos fiscales y la geometría exacta no salen del panel. */
class ProveedorController extends Controller
{
    public function rubros(): JsonResponse
    {
        $locale = app()->getLocale();
        $data = DB::table('rubros_proveedor')->where('activo', true)->orderBy('codigo')->get()->map(fn ($r) => [
            'codigo' => $r->codigo, 'nombre' => json_decode($r->nombres, true)[$locale] ?? json_decode($r->nombres, true)['es'],
        ]);

        return response()->json(['data' => $data], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function perfil(Request $request): JsonResponse
    {
        $p = DB::table('proveedores')->where('usuario_id', $request->user()->id)->first() ?? throw ApiException::noEncontrado(__('Todavía no tenés perfil de proveedor.'));
        $rubros = DB::table('proveedor_rubros as pr')->join('rubros_proveedor as r', 'r.id', '=', 'pr.rubro_id')->where('pr.proveedor_id', $p->usuario_id)->pluck('r.codigo')->all();

        return response()->json(['data' => ['slug' => $p->slug, 'nombre_publico' => $p->nombre_publico, 'razon_social_declarada' => $p->razon_social_declarada,
            'cuit' => null, 'estado_cuit' => $p->estado_cuit, 'rubros' => $rubros, 'provincias' => json_decode($p->provincias, true),
            'razon_social_informada' => $p->razon_social_informada, 'fuente_cuit' => $p->fuente_cuit, 'cuit_validado_en' => $p->validado_en,
            'descripciones' => json_decode($p->descripciones, true), 'idiomas' => self::textArray($p->idiomas), 'publico' => (bool) $p->publico]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function guardarPerfil(Request $request): JsonResponse
    {
        $u = $request->user();
        $existente = DB::table('proveedores')->where('usuario_id', $u->id)->first();
        $d = $request->validate([
            'slug' => ['required', 'regex:/^[a-z0-9-]{3,60}$/', Rule::unique('proveedores', 'slug')->ignore($u->id, 'usuario_id')],
            'nombre_publico' => ['required', 'string', 'min:3', 'max:120'], 'razon_social_declarada' => ['required', 'string', 'max:200'],
            'cuit' => [$existente ? 'sometimes' : 'required', 'string', new Cuit], 'rubros' => ['required', 'array', 'min:1', 'max:15'], 'rubros.*' => ['string', 'distinct', 'exists:rubros_proveedor,codigo'],
            'provincias' => ['required', 'array', 'min:1', 'max:10'], 'provincias.*' => ['string', 'size:2'],
            'cobertura' => ['sometimes', 'array'], 'cobertura.type' => ['required_with:cobertura', 'in:MultiPolygon'], 'cobertura.coordinates' => ['required_with:cobertura', 'array'],
            'descripciones' => ['required', 'array'], 'descripciones.es' => ['required', 'string', 'min:20', 'max:2000'],
            'descripciones.en' => ['required', 'string', 'min:20', 'max:2000'], 'descripciones.pt' => ['required', 'string', 'min:20', 'max:2000'],
            'idiomas' => ['sometimes', 'array'], 'idiomas.*' => ['in:es,en,pt'], 'publico' => ['sometimes', 'boolean'],
        ]);
        $cuitNuevo = array_key_exists('cuit', $d);
        $digitos = $cuitNuevo ? preg_replace('/\D/', '', $d['cuit']) : null;
        if (($d['publico'] ?? false) && ! $u->email_verificado_en) {
            throw new ApiException(403, 'email_no_verificado', __('Verificá tu email para publicar el perfil.'));
        }
        $hash = $cuitNuevo ? hash_hmac('sha256', $digitos, (string) config('tropilla.cuit_pepper')) : $existente->cuit_hash;
        if (DB::table('proveedores')->where('cuit_hash', $hash)->where('usuario_id', '<>', $u->id)->exists()) {
            throw new ApiException(422, 'validacion', __('Ese CUIT ya está asociado a otra cuenta.'));
        }
        if ($cuitNuevo) {
            $fuente = app(BcraCentralDeudores::class)->consultar($digitos);
            $informada = $fuente['datos']['denominacion'] ?? null;
            $coincide = is_string($informada) && mb_strtolower(trim($informada)) === mb_strtolower(trim($d['razon_social_declarada']));
            $estadoCuit = $fuente['estado'] !== 'ok' || ! $informada ? 'pendiente' : ($coincide ? 'validado' : 'inconsistente');
        } else {
            $fuente = ['codigo' => $existente->fuente_cuit, 'consultado_en' => $existente->validado_en];
            $informada = $existente->razon_social_informada;
            $coincide = is_string($informada) && mb_strtolower(trim($informada)) === mb_strtolower(trim($d['razon_social_declarada']));
            $estadoCuit = $informada ? ($coincide ? 'validado' : 'inconsistente') : $existente->estado_cuit;
        }
        DB::transaction(function () use ($d, $u, $digitos, $hash, $existente, $fuente, $informada, $estadoCuit, $cuitNuevo): void {
            $values = ['slug' => $d['slug'], 'nombre_publico' => $d['nombre_publico'], 'razon_social_declarada' => $d['razon_social_declarada'],
                'razon_social_informada' => $informada,
                'cuit_cifrado' => $cuitNuevo ? encrypt($digitos) : $existente->cuit_cifrado, 'cuit_hash' => $hash,
                'estado_cuit' => $estadoCuit, 'fuente_cuit' => $fuente['codigo'], 'validado_en' => $fuente['consultado_en'], 'descripciones' => json_encode((object) $d['descripciones']),
                'idiomas' => json_encode(array_values(array_unique($d['idiomas'] ?? ['es']))), 'provincias' => json_encode(array_values(array_unique($d['provincias']))),
                'publico' => $d['publico'] ?? false, 'updated_at' => now()];
            if (array_key_exists('cobertura', $d)) {
                $values['cobertura'] = DB::raw("ST_SetSRID(ST_GeomFromGeoJSON('".str_replace("'", "''", json_encode($d['cobertura']))."'),4326)::geography");
            }
            if ($existente) {
                DB::table('proveedores')->where('usuario_id', $u->id)->update($values);
            } else {
                DB::table('proveedores')->insert($values + ['usuario_id' => $u->id, 'created_at' => now()]);
            }
            if (array_key_exists('cobertura', $d)) {
                $validez = DB::selectOne('SELECT ST_IsValid((SELECT cobertura::geometry FROM proveedores WHERE usuario_id = ?)) AS valida', [$u->id]);
                if (! $validez->valida) {
                    throw new ApiException(422, 'validacion', __('La zona de cobertura dibujada no es válida.'));
                }
            }
            DB::table('proveedor_rubros')->where('proveedor_id', $u->id)->delete();
            $rubros = DB::table('rubros_proveedor')->whereIn('codigo', $d['rubros'])->pluck('id');
            foreach ($rubros as $rubroId) {
                DB::table('proveedor_rubros')->insert(['proveedor_id' => $u->id, 'rubro_id' => $rubroId]);
            }
        });

        return $this->perfil($request);
    }

    public function publico(Request $request, string $slug): JsonResponse
    {
        $locale = $request->query('locale', 'es');
        abort_unless(in_array($locale, ['es', 'en', 'pt'], true), 422);
        $p = DB::table('proveedores')->where('slug', $slug)->where('publico', true)->first() ?? throw ApiException::noEncontrado();
        $nombres = json_decode($p->descripciones, true);
        $rubros = DB::table('proveedor_rubros as pr')->join('rubros_proveedor as r', 'r.id', '=', 'pr.rubro_id')->where('pr.proveedor_id', $p->usuario_id)->get(['r.codigo', 'r.nombres']);
        $calificacion = DB::table('resenas_proveedor')->where('proveedor_id', $p->usuario_id)->avg('puntaje');

        return response()->json(['data' => ['slug' => $p->slug, 'nombre' => $p->nombre_publico, 'descripcion' => $nombres[$locale],
            'rubros' => $rubros->map(fn ($r) => ['codigo' => $r->codigo, 'nombre' => json_decode($r->nombres, true)[$locale] ?? json_decode($r->nombres, true)['es']]),
            'provincias' => json_decode($p->provincias, true), 'idiomas' => self::textArray($p->idiomas), 'patrocinado' => (bool) $p->patrocinado,
            'calificacion' => $calificacion ? round((float) $calificacion, 1) : null, 'cantidad_resenas' => DB::table('resenas_proveedor')->where('proveedor_id', $p->usuario_id)->count(),
            'es_demo' => (bool) $p->es_demo, 'leyenda' => __('Información declarada por el proveedor.')]], 200, ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=600'], JSON_UNESCAPED_UNICODE);
    }

    public function buscar(Request $request): JsonResponse
    {
        $d = $request->validate(['rubro' => ['sometimes', 'exists:rubros_proveedor,codigo'], 'provincia' => ['sometimes', 'string', 'size:2'],
            'lat' => ['required_with:lon', 'numeric', 'between:-90,90'], 'lon' => ['required_with:lat', 'numeric', 'between:-180,180'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $q = DB::table('proveedores as p')->where('p.publico', true)
            ->when($d['rubro'] ?? null, fn ($q, $r) => $q->whereExists(fn ($sq) => $sq->selectRaw('1')->from('proveedor_rubros as pr')->join('rubros_proveedor as r', 'r.id', '=', 'pr.rubro_id')->whereColumn('pr.proveedor_id', 'p.usuario_id')->where('r.codigo', $r)))
            ->when($d['provincia'] ?? null, fn ($q, $prov) => $q->whereRaw('p.provincias @> ?::jsonb', [json_encode([$prov])]))
            ->when(isset($d['lat'], $d['lon']), fn ($q) => $q->whereRaw('ST_Intersects(p.cobertura, ST_SetSRID(ST_MakePoint(?, ?),4326)::geography)', [$d['lon'], $d['lat']]))
            ->orderByDesc('p.patrocinado')->orderBy('p.nombre_publico')->select(['p.slug', 'p.nombre_publico', 'p.descripciones', 'p.provincias', 'p.idiomas', 'p.patrocinado', 'p.es_demo']);

        $page = $q->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor');

        return response()->json(['data' => $page->getCollection()->map(fn ($p) => self::publicItem($p, $request->query('locale', 'es')))->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'prev_cursor' => $page->previousCursor()?->encode(), 'limit' => $page->perPage()]]);
    }

    public function solicitar(Request $request, string $slug): JsonResponse
    {
        $d = $request->validate(['establecimiento_id' => ['required', 'uuid', 'exists:establecimientos,id'], 'rubro' => ['required', 'exists:rubros_proveedor,codigo'], 'mensaje' => ['required', 'string', 'min:10', 'max:2000']]);
        $proveedor = DB::table('proveedores')->where('slug', $slug)->where('publico', true)->first() ?? throw ApiException::noEncontrado();
        $est = Establecimiento::query()->find($d['establecimiento_id']) ?? throw ApiException::noEncontrado();
        Gate::authorize('consultas.ver', $est);
        $asignado = DB::table('proveedor_rubros as pr')->join('rubros_proveedor as r', 'r.id', '=', 'pr.rubro_id')->where('pr.proveedor_id', $proveedor->usuario_id)->where('r.codigo', $d['rubro'])->exists();
        abort_unless($asignado, 422);
        $id = (string) Str::uuid7();
        DB::table('leads_proveedor')->insert(['id' => $id, 'proveedor_id' => $proveedor->usuario_id, 'establecimiento_id' => $est->id,
            'iniciado_por' => $request->user()->id, 'rubro_codigo' => $d['rubro'], 'mensaje' => $d['mensaje'], 'created_at' => now(), 'updated_at' => now()]);
        app(Notificador::class)->aUsuario($proveedor->usuario_id, null, 'lead_proveedor', 'Nuevo pedido de presupuesto', 'Un productor inició un pedido desde el directorio.', ['lead_id' => $id], 'lead-proveedor-'.$id);

        return response()->json(['data' => ['id' => $id, 'estado' => 'nuevo']], 201);
    }

    public function leads(Request $request): JsonResponse
    {
        $proveedor = DB::table('proveedores')->where('usuario_id', $request->user()->id)->first() ?? throw ApiException::noEncontrado();
        $page = DB::table('leads_proveedor as l')->join('establecimientos as e', 'e.id', '=', 'l.establecimiento_id')
            ->where('l.proveedor_id', $proveedor->usuario_id)->orderByDesc('l.created_at')->orderByDesc('l.id')
            ->select(['l.id', 'l.rubro_codigo', 'l.mensaje', 'l.estado', 'l.created_at', 'e.nombre as establecimiento_nombre'])
            ->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor');

        return response()->json(['data' => $page->getCollection()->map(fn ($lead) => ['id' => $lead->id, 'rubro' => $lead->rubro_codigo,
            'mensaje' => $lead->mensaje, 'estado' => $lead->estado, 'establecimiento_nombre' => $lead->establecimiento_nombre, 'creado_en' => $lead->created_at])->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'prev_cursor' => $page->previousCursor()?->encode(), 'limit' => $page->perPage()]]);
    }

    public function resenar(Request $request, string $slug, string $operacion): JsonResponse
    {
        $d = $request->validate(['puntaje' => ['required', 'integer', 'between:1,5'], 'comentario' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $p = DB::table('proveedores')->where('slug', $slug)->where('publico', true)->first() ?? throw ApiException::noEncontrado();
        $op = DB::table('operaciones')->where('id', $operacion)->where('proveedor_id', $p->usuario_id)->where('estado', 'registrada')->first() ?? throw ApiException::noEncontrado();
        Gate::authorize('comercial.precios', Establecimiento::query()->findOrFail($op->establecimiento_id));
        if (DB::table('resenas_proveedor')->where('proveedor_id', $p->usuario_id)->where('operacion_id', $op->id)->exists()) {
            throw new ApiException(409, 'ya_resenado', __('Ya dejaste una reseña para esta operación.'));
        }
        $id = (string) Str::uuid7();
        DB::table('resenas_proveedor')->insert(['id' => $id, 'proveedor_id' => $p->usuario_id, 'operacion_id' => $op->id,
            'autor_id' => $request->user()->id, 'puntaje' => $d['puntaje'], 'comentario' => $d['comentario'] ?? null, 'created_at' => now()]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    private static function publicItem(object $p, string $locale): array
    {
        $d = json_decode($p->descripciones, true);

        return ['slug' => $p->slug, 'nombre' => $p->nombre_publico, 'descripcion' => $d[$locale] ?? $d['es'], 'provincias' => json_decode($p->provincias, true),
            'idiomas' => self::textArray($p->idiomas), 'patrocinado' => (bool) $p->patrocinado, 'es_demo' => (bool) $p->es_demo];
    }

    private static function textArray(string $array): array
    {
        return json_decode($array, true) ?: [];
    }
}
