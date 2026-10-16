<?php

namespace App\Http\Resources\V1;

use App\Exceptions\ApiException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cursor opaco para listados con orden elegible (api-v1 §4): base64url de {posición, huella de filtros}.
 * Cambiar filtros u orden con un cursor viejo → 400 `request_invalido`. Responde solo `data` + `meta`.
 */
final class CursorOffset
{
    /**
     * @param  array<string, mixed>  $filtros  los que definen el listado (q, especie, orden, ...)
     * @param  callable(object): array<string, mixed>  $mapear
     */
    public static function responder(Builder $q, Request $request, array $filtros, callable $mapear): JsonResponse
    {
        $limite = CursorRespuesta::limite($request);
        ksort($filtros);
        $firma = substr(hash('sha256', json_encode($filtros)), 0, 16);
        $offset = 0;

        if ($cursor = $request->query('cursor')) {
            $datos = json_decode((string) base64_decode(strtr((string) $cursor, '-_', '+/'), true), true);
            if (! is_array($datos) || ($datos['f'] ?? null) !== $firma || ! is_int($datos['o'] ?? null) || $datos['o'] < 0) {
                throw new ApiException(400, 'request_invalido', __('El cursor no corresponde a estos filtros.'));
            }
            $offset = $datos['o'];
        }

        $filas = (clone $q)->offset($offset)->limit($limite + 1)->get();
        $hayMas = $filas->count() > $limite;
        $cod = fn (int $o) => rtrim(strtr(base64_encode(json_encode(['o' => $o, 'f' => $firma])), '+/', '-_'), '=');

        return response()->json([
            'data' => $filas->take($limite)->map($mapear)->values()->all(),
            'meta' => [
                'next_cursor' => $hayMas ? $cod($offset + $limite) : null,
                'prev_cursor' => $offset > 0 ? $cod(max(0, $offset - $limite)) : null,
                'limit' => $limite,
            ],
        ]);
    }
}
