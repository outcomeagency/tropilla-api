<?php

namespace App\Http\Controllers\Api\V1\Campos;

use App\Domain\Campos\Actions\Disputas;
use App\Domain\Campos\Actions\GestionarAviso;
use App\Domain\Campos\Actions\GestionarConsulta;
use App\Domain\Campos\Campos;
use App\Domain\Campos\Compatibilidad;
use App\Domain\Establecimientos\Data\Provincias;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Rules\Reglas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Mercado de campos y pastajes (TPA-74): búsqueda de avisos publicados, compatibilidad con el rodeo, consulta y reporte.
 * Visible para cualquier usuario con sesión; la compatibilidad y la consulta se hacen en nombre de un establecimiento propio.
 * Piloto regional (≈20–30 avisos): la búsqueda devuelve hasta `tropilla.campos.max_resultados` sin cursor.
 */
final class MercadoCampoController extends Controller
{
    public function condiciones(): JsonResponse
    {
        $lista = fn (array $m) => collect($m)->map(fn ($n, $c) => ['codigo' => $c, 'nombre' => $n])->values()->all();

        return self::json(['data' => [
            'provincias_piloto' => collect(Campos::provinciasPiloto())->map(fn ($c) => ['codigo' => $c, 'nombre' => Provincias::TODAS[$c] ?? $c])->all(),
            'modalidades' => $lista(Campos::MODALIDADES), 'precio_unidades' => $lista(Campos::PRECIO_UNIDADES),
            'agua' => $lista(Campos::AGUA), 'instalaciones' => $lista(Campos::INSTALACIONES), 'alambrado' => $lista(Campos::ALAMBRADO),
            'verificacion' => collect(Campos::VERIFICACION)->map(fn ($v, $c) => ['codigo' => $c, ...$v])->values()->all(),
            'motivos_disputa' => $lista(Campos::MOTIVOS_DISPUTA), 'motivos_cierre' => $lista(GestionarAviso::MOTIVOS_CIERRE),
            'estados_consulta' => $lista(Campos::ESTADOS_CONSULTA),
            'checklist' => Campos::CHECKLIST, 'avisos' => Campos::AVISOS,
            'consentimiento_version' => Campos::CONSENTIMIENTO_VERSION, 'umbral_revision' => (int) config('tropilla.campos.umbral_revision'),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'establecimiento_id' => ['sometimes', Reglas::UUID],
            'modalidad' => ['sometimes', Rule::in(array_keys(Campos::MODALIDADES))],
            'especie' => ['sometimes', 'exists:especies,codigo'],
            'categorias' => ['sometimes', 'array'], 'categorias.*' => ['string', 'max:40'],
            'provincia' => ['sometimes', 'string', 'size:2'], 'departamento' => ['sometimes', 'string', 'max:5'],
            'cabezas' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'desde' => ['sometimes', 'date_format:Y-m-d'], 'hasta' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'solo_compatibles' => ['sometimes', 'boolean'], 'orden' => ['sometimes', Rule::in(['compatibilidad', 'recientes', 'capacidad', 'distancia'])],
        ]);
        $est = isset($f['establecimiento_id']) ? $this->miEstablecimiento($request, $f['establecimiento_id']) : null;
        $hoy = now()->toDateString();
        $avisos = DB::table('campo_avisos')->where('estado', 'publicado')
            ->where(fn ($q) => $q->whereNull('temporada_hasta')->orWhere('temporada_hasta', '>=', $hoy))
            ->when($f['modalidad'] ?? null, fn ($q, $v) => $q->where('modalidad', $v))
            ->when($f['especie'] ?? null, fn ($q, $v) => $q->whereRaw('? = ANY(especies)', [$v]))
            ->when($f['provincia'] ?? null, fn ($q, $v) => $q->where('provincia_codigo', $v))
            ->when($f['departamento'] ?? null, fn ($q, $v) => $q->where('departamento_codigo', $v))
            ->when(($f['solo_compatibles'] ?? false) ? null : ($f['cabezas'] ?? null), fn ($q, $v) => $q->where('capacidad_cabezas', '>=', $v))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->whereNull('temporada_hasta')->orWhere('temporada_hasta', '>=', $v)))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('temporada_desde', '<=', $v))
            ->orderByDesc('publicado_en')->orderByDesc('id')->limit((int) config('tropilla.campos.max_resultados'))->get();

        $rodeo = $est ? Compatibilidad::rodeo($est) : [];
        $distancias = $est ? Compatibilidad::distancias(Compatibilidad::origen($est), $avisos->pluck('departamento_codigo')->all()) : [];
        $pedido = ['especie' => $f['especie'] ?? null, 'cabezas' => $f['cabezas'] ?? null, 'categorias' => $f['categorias'] ?? [], 'desde' => $f['desde'] ?? null, 'hasta' => $f['hasta'] ?? null];
        $filas = $avisos->map(fn ($a) => ['aviso' => $a, 'compat' => ($est || $pedido['especie']) ? Compatibilidad::evaluar($a, $rodeo, $pedido, $distancias[$a->departamento_codigo] ?? null) : null,
            'propio' => $est !== null && $a->establecimiento_id === $est->id]);
        if ($f['solo_compatibles'] ?? false) {
            $filas = $filas->filter(fn ($x) => ($x['compat']['compatible'] ?? false) && ! $x['propio']);
        }
        $orden = $f['orden'] ?? ($est || $pedido['especie'] ? 'compatibilidad' : 'recientes');
        $filas = match ($orden) {
            'compatibilidad' => $filas->sortByDesc(fn ($x) => [$x['compat']['puntaje'] ?? -1, $x['aviso']->publicado_en]),
            'capacidad' => $filas->sortByDesc(fn ($x) => (int) $x['aviso']->capacidad_cabezas),
            'distancia' => $filas->sortBy(fn ($x) => $x['compat']['distancia_km'] ?? PHP_INT_MAX),
            default => $filas,
        };

        return self::json([
            'data' => $filas->values()->map(fn ($x) => [...Campos::aviso($x['aviso']), 'compatibilidad' => $x['compat'], 'propio' => $x['propio']])->all(),
            'meta' => ['total' => $filas->count(), 'orden' => $orden, 'rodeo' => $est ? collect($rodeo)->map(fn ($r, $e) => ['especie' => $e, 'cabezas' => $r['cabezas']])->values()->all() : null],
        ]);
    }

    public function show(Request $request, string $aviso): JsonResponse
    {
        $f = $request->validate(['establecimiento_id' => ['sometimes', Reglas::UUID], 'cabezas' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $a = $this->aviso($aviso);
        $propio = DB::table('membresias')->where('establecimiento_id', $a->establecimiento_id)->where('usuario_id', $request->user()->id)->where('estado', 'activa')->exists();
        if ($a->estado !== 'publicado' && ! $propio && ! $request->user()->es_staff) {
            throw ApiException::noEncontrado();
        }
        $est = isset($f['establecimiento_id']) ? $this->miEstablecimiento($request, $f['establecimiento_id']) : null;
        $compat = null;
        $miConsulta = null;
        if ($est) {
            $dist = Compatibilidad::distancias(Compatibilidad::origen($est), [$a->departamento_codigo]);
            $compat = Compatibilidad::evaluar($a, Compatibilidad::rodeo($est), ['cabezas' => $f['cabezas'] ?? null], $dist[$a->departamento_codigo] ?? null);
            $c = DB::table('campo_consultas')->where('aviso_id', $a->id)->where('establecimiento_id', $est->id)->orderByDesc('created_at')->first();
            $miConsulta = $c ? Campos::consulta($c, 'enviada') : null;
        }
        $reportado = DB::table('campo_disputas')->where('aviso_id', $a->id)->where('denunciante_id', $request->user()->id)->whereIn('estado', ['abierta', 'en_revision'])->exists();

        return self::json(['data' => [...Campos::aviso($a), 'compatibilidad' => $compat, 'propio' => $propio, 'mi_consulta' => $miConsulta,
            'reportado' => $reportado, 'checklist' => Campos::CHECKLIST, 'avisos' => Campos::AVISOS]]);
    }

    public function consultar(Request $request, string $aviso, GestionarConsulta $consultas): JsonResponse
    {
        $d = $request->validate([
            'establecimiento_id' => ['required', Reglas::UUID],
            'mensaje' => ['required', 'string', 'min:10', 'max:2000'],
            'especie' => ['required', 'exists:especies,codigo'],
            'cabezas' => ['required', 'integer', 'min:1', 'max:100000'],
            'categorias' => ['sometimes', 'array', 'max:20'], 'categorias.*' => ['distinct', Rule::exists('categorias', 'codigo')->where('especie_codigo', $request->input('especie'))],
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'consentimiento' => ['required', 'accepted'],
        ], ['consentimiento.accepted' => 'Para consultar tenés que aceptar compartir tu contacto con quien publicó.']);
        $est = $this->miEstablecimiento($request, $d['establecimiento_id'], 'campos.consultar');
        $c = $consultas->enviar($this->aviso($aviso), $est, $request->user(), $d);

        return self::json(['data' => Campos::consulta($c, 'enviada')], 201);
    }

    public function reportar(Request $request, string $aviso, Disputas $disputas): JsonResponse
    {
        $d = $request->validate([
            'motivo' => ['required', Rule::in(array_keys(Campos::MOTIVOS_DISPUTA))],
            'detalle' => ['required', 'string', 'min:20', 'max:3000'],
            'consulta_id' => ['sometimes', 'nullable', Reglas::UUID],
        ]);
        $a = $this->aviso($aviso);
        if (! in_array($a->estado, ['publicado', 'pausado', 'en_revision', 'cerrado'], true)) {
            throw ApiException::noEncontrado();
        }

        return self::json(['data' => Campos::disputa($disputas->reportar($a, $request->user(), $d))], 201);
    }

    private function aviso(string $id): object
    {
        Str::isUuid($id) || throw ApiException::noEncontrado();

        return DB::table('campo_avisos')->where('id', strtolower($id))->first() ?? throw ApiException::noEncontrado();
    }

    private function miEstablecimiento(Request $request, string $id, string $ability = 'campos.ver'): Establecimiento
    {
        $est = Establecimiento::query()->whereKey(strtolower($id))->whereNull('archivado_en')->first() ?? throw ApiException::noEncontrado();
        Gate::forUser($request->user())->authorize($ability, $est);

        return $est;
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE);
    }
}
