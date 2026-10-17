<?php

namespace App\Http\Controllers\Api\V1\Campos;

use App\Domain\Campos\Actions\Disputas;
use App\Domain\Campos\Campos;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Moderación del mercado de campos (TPA-74), solo equipo de TROPILLA (`es_staff`): disputas y nivel de verificación. */
final class ModeracionCampoController extends Controller
{
    public function disputas(Request $request): JsonResponse
    {
        self::staff($request);
        $estado = $request->query('estado', 'pendientes');
        $filas = DB::table('campo_disputas')
            ->when($estado === 'pendientes', fn ($q) => $q->whereIn('estado', ['abierta', 'en_revision']), fn ($q) => $q->where('estado', $estado))
            ->orderBy('created_at')->limit(200)->get();

        return self::json(['data' => $filas->map(fn ($d) => Campos::disputa($d, true))->all()]);
    }

    public function resolver(Request $request, string $disputa, Disputas $disputas): JsonResponse
    {
        self::staff($request);
        $d = $request->validate([
            'estado' => ['required', Rule::in(['en_revision', 'resuelta', 'desestimada'])],
            'resolucion' => ['required_unless:estado,en_revision', 'nullable', 'string', 'min:10', 'max:3000'],
            'accion' => ['sometimes', 'nullable', Rule::in(array_keys(Disputas::ACCIONES))],
        ]);
        Str::isUuid($disputa) || throw ApiException::noEncontrado();
        $x = DB::table('campo_disputas')->where('id', strtolower($disputa))->first() ?? throw ApiException::noEncontrado();

        return self::json(['data' => Campos::disputa($disputas->resolver($x, $request->user(), $d), true)]);
    }

    public function verificar(Request $request, string $aviso, Disputas $disputas): JsonResponse
    {
        self::staff($request);
        $d = $request->validate(['nivel' => ['required', Rule::in(array_keys(Campos::VERIFICACION))], 'nota' => ['required', 'string', 'min:10', 'max:1000']]);
        Str::isUuid($aviso) || throw ApiException::noEncontrado();
        $a = DB::table('campo_avisos')->where('id', strtolower($aviso))->first() ?? throw ApiException::noEncontrado();

        return self::json(['data' => Campos::aviso($disputas->verificar($a, $request->user(), $d['nivel'], $d['nota']), true)]);
    }

    private static function staff(Request $request): void
    {
        $request->user()->es_staff || throw ApiException::sinPermiso('La moderación del mercado de campos la hace el equipo de TROPILLA.');
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE);
    }
}
