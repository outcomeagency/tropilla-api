<?php

namespace App\Http\Controllers\Api\V1\Publico;

use App\Domain\Contratos\ConsultasContratos;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Leyenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** GET /public/contratos/{codigo} (sesión opcional): precio especial y anticipo solo para compradores con cuenta. */
class ContratoPublicoController extends Controller
{
    public const VISIBLES = ['publicado', 'parcialmente_tomado', 'completo'];

    public function show(Request $request, string $codigo): JsonResponse
    {
        $c = DB::table('contratos_futuro')->where('codigo_publico', strtoupper($codigo))->whereIn('estado', [...self::VISIBLES, 'entregado', 'liquidado'])->first()
            ?? throw ApiException::noEncontrado();

        return response()->json(['data' => ConsultasContratos::publico($c, self::esComprador(), Leyenda::locale($request->query('locale')))],
            200, ['Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization, Accept-Language'], JSON_UNESCAPED_UNICODE);
    }

    /** Sesión de comprador: token válido + perfil de comprador (TPA-57). Sin sesión → null en los precios. */
    public static function esComprador(): bool
    {
        $u = Auth::guard('sanctum')->user();

        return $u !== null && DB::getSchemaBuilder()->hasTable('compradores') && DB::table('compradores')->where('usuario_id', $u->id)->exists();
    }
}
