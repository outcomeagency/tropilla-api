<?php

namespace App\Http\Controllers\Api\V1\Comercial;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Comercial\ConsultaResource;
use App\Http\Resources\V1\CursorRespuesta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Bandeja de consultas de compradores (TPA-49). Solo dueño/admin (datos personales del comprador). */
class ConsultaController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('consultas.ver', $establecimiento);
        $q = DB::table('consultas')->where('establecimiento_id', $establecimiento->id)
            ->when($request->query('estado'), fn ($q, $e) => $q->where('estado', $e), fn ($q) => $q->where('estado', '<>', 'spam'))
            ->orderByDesc('created_at')->orderByDesc('id');

        return CursorRespuesta::make($q->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor'), ConsultaResource::class, $request);
    }

    /** Ver consulta: la marca como vista (no cambia el estado: «nueva» hasta que se responde). */
    public function show(Establecimiento $establecimiento, string $consulta): ConsultaResource
    {
        Gate::authorize('consultas.ver', $establecimiento);
        $c = $this->consulta($establecimiento, $consulta);
        if ($c->vista_en === null) {
            DB::table('consultas')->where('id', $c->id)->update(['vista_en' => now()]);
        }

        return ConsultaResource::make($c);
    }

    public function estado(Request $request, Establecimiento $establecimiento, string $consulta, RegistrarAuditoria $auditoria): ConsultaResource
    {
        Gate::authorize('consultas.gestionar', $establecimiento);
        $d = $request->validate(['estado' => ['required', 'in:respondida,cerrada,spam,nueva'], 'canal_respuesta' => ['nullable', 'in:whatsapp,email,telefono,otro']]);
        $c = $this->consulta($establecimiento, $consulta);
        $cambios = ['estado' => $d['estado'], 'updated_at' => now()];
        if ($d['estado'] === 'respondida') {
            $cambios += ['respondida_en' => $c->respondida_en ?? now(), 'respondida_por' => $request->user()->id, 'canal_respuesta' => $d['canal_respuesta'] ?? $c->canal_respuesta];
        }
        if ($d['estado'] === 'cerrada') {
            $cambios['cerrada_en'] = now();
        }
        DB::table('consultas')->where('id', $c->id)->update($cambios);
        $auditoria->handle($establecimiento->id, $request->user()->id, 'consulta', $c->id, 'editar', ['estado' => $c->estado], ['estado' => $d['estado']]);

        return ConsultaResource::make($this->consulta($establecimiento, $consulta));
    }

    private function consulta(Establecimiento $est, string $id): object
    {
        return DB::table('consultas')->where('id', $id)->where('establecimiento_id', $est->id)->first() ?? throw ApiException::noEncontrado();
    }
}
