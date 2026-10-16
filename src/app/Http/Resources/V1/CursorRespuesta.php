<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\CursorPaginator;

/**
 * Paginación por cursor de api-v1.md §4: emite **solo** `{data, meta: {next_cursor, prev_cursor, limit}}`
 * (sin URLs). El cursor es el opaco de `cursorPaginate()` (base64 de la clave de orden).
 */
final class CursorRespuesta
{
    public static function limite(Request $request): int
    {
        return max(1, min(100, (int) ($request->query('limit') ?: 25)));
    }

    /**
     * @param  CursorPaginator<int, mixed>  $paginator
     * @param  class-string<JsonResource>  $resource
     */
    public static function make(CursorPaginator $paginator, string $resource, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($paginator->getCollection())->toArray($request),
            'meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'limit' => $paginator->perPage(),
            ],
        ]);
    }
}
