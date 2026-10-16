<?php

namespace App\Http\Controllers\Api\V1\Organizaciones;

use App\Casts\PgTextArray;
use App\Domain\Establecimientos\Models\Productor;
use App\Domain\Establecimientos\Policies\ProductorPolicy;
use App\Domain\Integridad\Confianza;
use App\Domain\Organizaciones\Actions\GestionarAdhesion;
use App\Domain\Organizaciones\Policies\OrganizacionPolicy;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\MotivoRequest;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Organizaciones y adhesión con consentimiento (TPA-51). Alta de organizaciones y de sus administradores: equipo de TROPILLA
 * (`es_staff`; en MVP el alta es controlada). El padrón lo ven solo admin/operador de la organización y solo con lo consentido.
 */
class OrganizacionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['sometimes', 'string', 'max:100'], 'especie' => ['sometimes', 'exists:especies,codigo']]);
        $p = DB::table('organizaciones')->whereNull('archivado_en')
            ->when($f['q'] ?? null, fn ($q, $v) => $q->whereRaw('unaccent(nombre) ILIKE unaccent(?)', ['%'.addcslashes($v, '%_\\').'%']))
            ->when($f['especie'] ?? null, fn ($q, $v) => $q->whereRaw('? = ANY(especies)', [$v]))
            ->orderBy('nombre')->orderBy('id')->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return response()->json(['data' => collect($p->items())->map(fn ($o) => self::organizacion($o))->all(),
            'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(string $organizacion): JsonResponse
    {
        return response()->json(['data' => self::organizacion($this->org($organizacion))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Aditivo: alta de organización (solo equipo TROPILLA). */
    public function store(Request $request): JsonResponse
    {
        $request->user()->es_staff || throw ApiException::sinPermiso(__('El alta de organizaciones la hace el equipo de TROPILLA.'));
        $d = $request->validate(['nombre' => ['required', 'string', 'min:3', 'max:200'], 'slug' => ['required', 'regex:/^[a-z0-9-]{3,60}$/', 'unique:organizaciones,slug'],
            'tipo' => ['required', 'in:asociacion,programa_provincial,cooperativa,consejo_regulador,otra'], 'ambito' => ['sometimes', 'array'],
            'especies' => ['sometimes', 'array'], 'especies.*' => ['exists:especies,codigo'], 'descripcion' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $id = (string) Str::uuid7();
        DB::table('organizaciones')->insert(['id' => $id, 'nombre' => $d['nombre'], 'slug' => $d['slug'], 'tipo' => $d['tipo'], 'ambito' => json_encode((object) ($d['ambito'] ?? [])),
            'especies' => '{'.implode(',', $d['especies'] ?? []).'}', 'descripcion' => $d['descripcion'] ?? null, 'verificada_por_tropilla_en' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => self::organizacion(DB::table('organizaciones')->where('id', $id)->first())], 201, [], JSON_UNESCAPED_UNICODE);
    }

    /** Aditivo: designar administradores/operadores de la organización (equipo TROPILLA o un admin de la organización). */
    public function miembros(Request $request, string $organizacion, OrganizacionPolicy $policy): JsonResponse
    {
        $o = $this->org($organizacion);
        $policy->administrarIntegrantes($request->user(), $o->id) || throw ApiException::noEncontrado();
        $data = DB::table('organizacion_miembros as m')->join('usuarios as u', 'u.id', '=', 'm.usuario_id')
            ->where('m.organizacion_id', $o->id)->where('m.estado', 'activa')->orderBy('u.nombre')->orderBy('u.id')
            ->get(['u.id', 'u.nombre', 'u.email', 'm.rol'])->map(fn ($m) => ['usuario' => ['id' => $m->id, 'nombre' => $m->nombre, 'email' => $m->email], 'rol' => $m->rol]);

        return response()->json(['data' => $data]);
    }

    public function miembro(Request $request, string $organizacion, OrganizacionPolicy $policy): JsonResponse
    {
        $o = $this->org($organizacion);
        $d = $request->validate(['email' => ['required', 'email'], 'rol' => ['required', 'in:admin,operador']]);
        $policy->administrarIntegrantes($request->user(), $o->id) || throw ApiException::noEncontrado();
        $u = User::query()->whereRaw('lower(email) = lower(?)', [$d['email']])->first() ?? throw ApiException::validacion(__('No hay un usuario con ese email.'), ['email' => [__('Inexistente.')]]);
        DB::table('organizacion_miembros')->where('organizacion_id', $o->id)->where('usuario_id', $u->id)->where('estado', 'activa')->update(['estado' => 'reemplazada', 'updated_at' => now()]);
        DB::table('organizacion_miembros')->insert(['id' => (string) Str::uuid7(), 'organizacion_id' => $o->id, 'usuario_id' => $u->id, 'rol' => $d['rol'], 'estado' => 'activa', 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(null, 204);
    }

    public function cambiarRol(Request $request, string $organizacion, string $usuario, OrganizacionPolicy $policy): JsonResponse
    {
        $o = $this->org($organizacion);
        $policy->administrarIntegrantes($request->user(), $o->id) || throw ApiException::noEncontrado();
        $d = $request->validate(['rol' => ['required', 'in:admin,operador']]);
        $m = DB::table('organizacion_miembros')->where('organizacion_id', $o->id)->where('usuario_id', strtolower($usuario))->where('estado', 'activa')->first() ?? throw ApiException::noEncontrado();
        if ($m->rol !== $d['rol']) {
            DB::transaction(function () use ($m, $o, $d) {
                DB::table('organizacion_miembros')->where('id', $m->id)->update(['estado' => 'reemplazada', 'updated_at' => now()]);
                DB::table('organizacion_miembros')->insert(['id' => (string) Str::uuid7(), 'organizacion_id' => $o->id, 'usuario_id' => $m->usuario_id, 'rol' => $d['rol'], 'estado' => 'activa', 'created_at' => now(), 'updated_at' => now()]);
            });
        }

        return response()->json(null, 204);
    }

    public function quitarMiembro(Request $request, string $organizacion, string $usuario, OrganizacionPolicy $policy): JsonResponse
    {
        $o = $this->org($organizacion);
        $policy->administrarIntegrantes($request->user(), $o->id) || throw ApiException::noEncontrado();
        $count = DB::table('organizacion_miembros')->where('organizacion_id', $o->id)->where('usuario_id', strtolower($usuario))->where('estado', 'activa')->update(['estado' => 'revocada', 'updated_at' => now()]);
        $count || throw ApiException::noEncontrado();

        return response()->json(null, 204);
    }

    /** Padrón: adheridos activos con lo que cada uno consintió compartir (y nada más). */
    public function adheridos(Request $request, string $organizacion): JsonResponse
    {
        $o = $this->org($organizacion);
        $this->rolEn($request->user(), $o->id) !== null || throw ApiException::noEncontrado();
        $p = DB::table('adhesiones as a')->join('productores as p', 'p.id', '=', 'a.productor_id')->where('a.organizacion_id', $o->id)->where('a.estado', 'activa')
            ->orderBy('p.nombre')->orderBy('a.id')->select('a.*', 'p.nombre as productor_nombre')
            ->cursorPaginate(max(1, min(100, (int) ($request->query('limit') ?: 25))), cursorName: 'cursor');

        return response()->json(['data' => collect($p->items())->map(function ($a) {
            $c = DB::table('consentimientos')->where('adhesion_id', $a->id)->where('version', $a->version_vigente)->first();
            $alc = self::alcances($c->alcances);
            $ests = DB::table('establecimientos as e')->leftJoin('geo_departamentos as g', 'g.codigo', '=', 'e.departamento_codigo')
                ->where('e.productor_id', $a->productor_id)->whereNull('e.archivado_en')->get(['e.id', 'e.nombre', 'g.nombre as departamento']);
            $desde = DB::table('consentimientos')->where('adhesion_id', $a->id)->where('accion', 'otorga')->orderByDesc('version')->value('created_at');

            return [
                'productor' => ['id' => $a->productor_id, 'nombre' => $a->productor_nombre],
                'establecimientos' => $ests->map(fn ($e) => ['nombre' => $e->nombre, 'departamento' => (string) $e->departamento])->all(),
                'numero_socio' => $a->numero_socio, 'alcances' => $alc, 'desde' => Formato::fechaHora(Carbon::parse($desde)),
                // Aditivo: solo lo consentido (datos nuevos desde que se otorga; al revocar deja de aparecer).
                'datos' => [
                    'existencias' => $alc['existencias_agregadas'] ? DB::table('existencias')->whereIn('establecimiento_id', $ests->pluck('id'))
                        ->groupBy('especie_codigo')->selectRaw('especie_codigo as especie, sum(cabezas)::int as cabezas')->get()->all() : null,
                    'cobertura_sanitaria' => $alc['cobertura_sanitaria'] ? DB::table('eventos_vigentes')->whereIn('establecimiento_id', $ests->pluck('id'))
                        ->where('accion', '<>', 'anulacion')->where('tipo', 'sanidad.vacunacion')->where('ocurrido_en', '>=', now()->subYear())
                        ->selectRaw("payload->>'enfermedad' as enfermedad, count(*)::int as registros")->groupByRaw("payload->>'enfermedad'")->orderBy('enfermedad')->get()->all() : null,
                ],
            ];
        })->all(), 'meta' => ['next_cursor' => $p->nextCursor()?->encode(), 'prev_cursor' => $p->previousCursor()?->encode(), 'limit' => $p->perPage()]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ---- Lado productor («Mis organizaciones») ----

    public function adhesiones(Request $request, Productor $productor): JsonResponse
    {
        $this->puede($request->user(), $productor, 'organizaciones.adherir');
        $as = DB::table('adhesiones')->where('productor_id', $productor->id)->orderBy('created_at')->get();

        return response()->json(['data' => $as->map(fn ($a) => self::adhesion($a))->all()], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function adherir(Request $request, Productor $productor, GestionarAdhesion $g): JsonResponse
    {
        $est = $this->puede($request->user(), $productor, 'organizaciones.adherir');
        $d = $request->validate(['organizacion_id' => ['required', 'uuid'], 'numero_socio' => ['sometimes', 'nullable', 'string', 'max:40'], ...$this->reglasConsentimiento()]);
        $id = $g->adherir($productor->id, $request->user(), $d, $est);

        return response()->json(['data' => self::adhesion(DB::table('adhesiones')->where('id', $id)->first())], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function consentimiento(Request $request, Productor $productor, string $adhesion, GestionarAdhesion $g): JsonResponse
    {
        $est = $this->puede($request->user(), $productor, 'consentimientos.gestionar');
        $d = $request->validate($this->reglasConsentimiento());
        $a = $this->adhesionDe($productor, $adhesion);
        $g->modificar($a, $request->user(), $d['alcances'], $d['texto_version'], $est);

        return response()->json(['data' => self::adhesion(DB::table('adhesiones')->where('id', $a->id)->first())], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function revocar(MotivoRequest $request, Productor $productor, string $adhesion, GestionarAdhesion $g): JsonResponse
    {
        $est = $this->puede($request->user(), $productor, 'consentimientos.gestionar');
        $a = $this->adhesionDe($productor, $adhesion);
        $g->revocar($a, $request->user(), $request->validated('motivo'), $est);

        return response()->json(['data' => self::adhesion(DB::table('adhesiones')->where('id', $a->id)->first())], 201, [], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function reglasConsentimiento(): array
    {
        return ['alcances' => ['required', 'array'], 'alcances.existencias_agregadas' => ['required', 'boolean'], 'alcances.cobertura_sanitaria' => ['required', 'boolean'],
            'alcances.lotes_postulados' => ['required', 'boolean'], 'alcances.precios_agregados' => ['required', 'boolean'],
            'texto_version' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,60}$/']];
    }

    /** Ability del rol en algún establecimiento del productor; devuelve uno de ellos (para la auditoría). 404 sin membresía, 403 sin la ability. */
    private function puede(User $u, Productor $p, string $ability): ?string
    {
        $roles = ProductorPolicy::roles($u, $p);
        if ($roles->isEmpty()) {
            throw ApiException::noEncontrado();
        }
        if (! $roles->contains(fn ($r) => $r->puede($ability))) {
            throw ApiException::sinPermiso();
        }

        return ProductorPolicy::establecimientoEditable($u, $p);
    }

    private function adhesionDe(Productor $p, string $id): object
    {
        return DB::table('adhesiones')->where('productor_id', $p->id)->where('id', strtolower($id))->first() ?? throw ApiException::noEncontrado();
    }

    private function org(string $id): object
    {
        return DB::table('organizaciones')->where('id', strtolower($id))->whereNull('archivado_en')->first() ?? throw ApiException::noEncontrado();
    }

    private function rolEn(User $u, string $orgId): ?string
    {
        return DB::table('organizacion_miembros')->where('organizacion_id', $orgId)->where('usuario_id', $u->id)->where('estado', 'activa')->value('rol');
    }

    /** @return array<string, mixed> */
    public static function organizacion(object $o): array
    {
        return ['id' => $o->id, 'slug' => $o->slug, 'nombre' => $o->nombre, 'tipo' => $o->tipo, 'ambito' => (object) json_decode($o->ambito, true),
            'especies' => PgTextArray::parse($o->especies), 'logo_url' => null, 'descripcion' => $o->descripcion];
    }

    /** Alcances en el orden del contrato (jsonb reordena las claves). @return array<string, bool> */
    private static function alcances(string $json): array
    {
        $a = json_decode($json, true);

        return array_combine(GestionarAdhesion::ALCANCES, array_map(fn ($k) => (bool) ($a[$k] ?? false), GestionarAdhesion::ALCANCES));
    }

    /** @return array<string, mixed> Adhesion */
    public static function adhesion(object $a): array
    {
        $cons = DB::table('consentimientos as c')->join('usuarios as u', 'u.id', '=', 'c.otorgado_por')->where('c.adhesion_id', $a->id)->orderBy('c.version')
            ->get(['c.*', 'u.nombre as otorgado_nombre'])->map(fn ($c) => ['version' => (int) $c->version, 'accion' => $c->accion, 'alcances' => self::alcances($c->alcances),
                'texto_version' => $c->texto_version, 'otorgado_por' => ['id' => $c->otorgado_por, 'nombre' => $c->otorgado_nombre], 'huella' => $c->hash,
                'confianza' => Confianza::deRegistro((int) $c->registro_integridad_id), 'created_at' => Formato::fechaHora(Carbon::parse($c->created_at))])->all();

        return ['id' => $a->id, 'organizacion' => self::organizacion(DB::table('organizaciones')->where('id', $a->organizacion_id)->first()), 'estado' => $a->estado,
            'numero_socio' => $a->numero_socio, 'consentimiento' => end($cons) ?: null, 'historial' => $cons];
    }
}
