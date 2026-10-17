<?php

namespace App\Http\Controllers\Api\V1\Campos;

use App\Domain\Campos\Actions\Disputas;
use App\Domain\Campos\Actions\GestionarAviso;
use App\Domain\Campos\Actions\GestionarConsulta;
use App\Domain\Campos\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Campos\AvisoCampoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Lado del establecimiento en el mercado de campos (TPA-74): sus avisos, las consultas recibidas y enviadas, y el descargo ante
 * un reporte. Gestionar y responder: dueño/admin (`campos.gestionar`); ver: también asesor (`campos.ver`).
 */
final class AvisoCampoController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('campos.ver', $establecimiento);
        $avisos = DB::table('campo_avisos')->where('establecimiento_id', $establecimiento->id)
            ->when($request->query('estado'), fn ($q, $v) => $q->where('estado', $v))
            ->orderByRaw("CASE estado WHEN 'en_revision' THEN 0 WHEN 'publicado' THEN 1 WHEN 'borrador' THEN 2 WHEN 'pausado' THEN 3 ELSE 4 END")
            ->orderByDesc('updated_at')->limit(200)->get();

        return self::json(['data' => $avisos->map(fn ($a) => Campos::aviso($a, true))->all()]);
    }

    public function show(Establecimiento $establecimiento, string $aviso): JsonResponse
    {
        Gate::authorize('campos.ver', $establecimiento);
        $a = self::propio($establecimiento, $aviso);
        $disputas = DB::table('campo_disputas')->where('aviso_id', $a->id)->orderByDesc('created_at')->get();

        return self::json(['data' => [...Campos::aviso($a, true), 'disputas' => $disputas->map(fn ($d) => Campos::disputa($d))->all()]]);
    }

    public function store(AvisoCampoRequest $request, Establecimiento $establecimiento, GestionarAviso $gestionar): JsonResponse
    {
        return self::json(['data' => Campos::aviso($gestionar->crear($establecimiento, $request->user(), $request->validated()), true)], 201);
    }

    public function update(AvisoCampoRequest $request, Establecimiento $establecimiento, string $aviso, GestionarAviso $gestionar): JsonResponse
    {
        $a = $gestionar->editar($establecimiento, $request->user(), self::propio($establecimiento, $aviso), $request->validated());

        return self::json(['data' => Campos::aviso($a, true)]);
    }

    public function estado(Request $request, Establecimiento $establecimiento, string $aviso, string $accion, GestionarAviso $gestionar): JsonResponse
    {
        Gate::authorize('campos.gestionar', $establecimiento);
        $d = $request->validate(['motivo' => [$accion === 'cerrar' ? 'required' : 'prohibited', Rule::in(array_keys(GestionarAviso::MOTIVOS_CIERRE))]]);
        $a = $gestionar->cambiarEstado($establecimiento, $request->user(), self::propio($establecimiento, $aviso), $accion, $d['motivo'] ?? null);

        return self::json(['data' => Campos::aviso($a, true)]);
    }

    public function descargo(Request $request, Establecimiento $establecimiento, string $aviso, string $disputa, Disputas $disputas): JsonResponse
    {
        Gate::authorize('campos.gestionar', $establecimiento);
        $d = $request->validate(['descargo' => ['required', 'string', 'min:10', 'max:3000']]);
        $a = self::propio($establecimiento, $aviso);
        Str::isUuid($disputa) || throw ApiException::noEncontrado();
        $x = DB::table('campo_disputas')->where('id', strtolower($disputa))->where('aviso_id', $a->id)->first() ?? throw ApiException::noEncontrado();

        return self::json(['data' => Campos::disputa($disputas->descargo($x, $request->user(), $d['descargo']))]);
    }

    public function consultas(Request $request, Establecimiento $establecimiento, string $lado): JsonResponse
    {
        Gate::authorize('campos.ver', $establecimiento);
        $q = DB::table('campo_consultas as c');
        $q = $lado === 'recibidas'
            ? $q->join('campo_avisos as a', 'a.id', '=', 'c.aviso_id')->where('a.establecimiento_id', $establecimiento->id)->select('c.*')
            : $q->where('c.establecimiento_id', $establecimiento->id);
        $filas = $q->when($request->query('estado') === 'abiertas', fn ($w) => $w->whereIn('c.estado', Campos::CONSULTAS_ABIERTAS))
            ->orderByDesc('c.updated_at')->orderByDesc('c.id')->limit(200)->get();

        return self::json(['data' => $filas->map(fn ($c) => Campos::consulta($c, $lado === 'recibidas' ? 'recibida' : 'enviada'))->all()]);
    }

    public function avanzar(Request $request, Establecimiento $establecimiento, string $lado, string $consulta, GestionarConsulta $gestionar): JsonResponse
    {
        Gate::authorize($lado === 'recibidas' ? 'campos.gestionar' : 'campos.consultar', $establecimiento);
        $d = $request->validate([
            'estado' => ['required', Rule::in(['respondida', 'visita_coordinada', 'acuerdo', 'sin_acuerdo', 'descartada', 'retirada'])],
            'respuesta' => ['sometimes', 'nullable', 'string', 'max:2000'], 'nota' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        Str::isUuid($consulta) || throw ApiException::noEncontrado();
        $q = DB::table('campo_consultas as c')->where('c.id', strtolower($consulta));
        $c = ($lado === 'recibidas'
            ? $q->join('campo_avisos as a', 'a.id', '=', 'c.aviso_id')->where('a.establecimiento_id', $establecimiento->id)->select('c.*')
            : $q->where('c.establecimiento_id', $establecimiento->id))->first() ?? throw ApiException::noEncontrado();
        $lado = $lado === 'recibidas' ? 'recibida' : 'enviada';

        return self::json(['data' => Campos::consulta($gestionar->avanzar($c, $lado, $request->user(), $d), $lado)]);
    }

    private static function propio(Establecimiento $est, string $id): object
    {
        Str::isUuid($id) || throw ApiException::noEncontrado();

        return DB::table('campo_avisos')->where('id', strtolower($id))->where('establecimiento_id', $est->id)->first() ?? throw ApiException::noEncontrado();
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE);
    }
}
