<?php

namespace App\Http\Controllers\Api\V1\Sistema;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CursorRespuesta;
use App\Http\Resources\V1\Sistema\AuditoriaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** GET /establecimientos/{e}/auditoria (bitácora de lo mutable, cursor). */
class AuditoriaController extends Controller
{
    public function __invoke(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('auditoria.ver', $establecimiento);

        $paginator = DB::table('auditoria')
            ->leftJoin('usuarios', 'usuarios.id', '=', 'auditoria.usuario_id')
            ->where('auditoria.establecimiento_id', $establecimiento->id)
            ->when($request->query('entidad'), fn ($q, $v) => $q->where('auditoria.entidad', $v))
            ->when($request->query('usuario_id'), fn ($q, $v) => $q->where('auditoria.usuario_id', $v))
            ->select('auditoria.*', 'usuarios.nombre as usuario_nombre')
            ->orderByDesc('auditoria.id')
            ->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor');

        return CursorRespuesta::make($paginator, AuditoriaResource::class, $request);
    }
}
