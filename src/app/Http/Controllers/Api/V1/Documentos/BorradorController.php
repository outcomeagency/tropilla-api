<?php

namespace App\Http\Controllers\Api\V1\Documentos;

use App\Domain\Documentos\Borradores\Actions\ConfirmarBorrador;
use App\Domain\Documentos\Borradores\Actions\CrearBorrador;
use App\Domain\Documentos\Borradores\Actions\RevisarCampos;
use App\Domain\Documentos\Borradores\ExtraerBorradorJob;
use App\Domain\Documentos\Borradores\Familias\Familias;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CursorOffset;
use App\Http\Resources\V1\Documentos\BorradorResource;
use App\Rules\Reglas;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Borradores de documento desde foto (TPA-61/62): lectura asistida, revisión campo por campo y confirmación explícita. */
class BorradorController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('documentos.ver', $establecimiento);
        $request->validate(['estado' => ['nullable', 'in:esperando_archivos,en_cola,extrayendo,para_revisar,fallido,confirmado,descartado'], 'documento_id' => ['nullable', Reglas::UUID]]);
        $q = $this->visibles($establecimiento)
            ->when($request->query('estado'), fn ($q, $e) => $q->where('b.estado', $e))
            ->when($request->query('documento_id'), fn ($q, $d) => $q->where('b.documento_id', $d))
            ->orderByDesc('b.created_at')->orderByDesc('b.id')->select('b.*');

        return CursorOffset::responder($q, $request, $request->only(['estado', 'documento_id']), fn ($b) => BorradorResource::make($b));
    }

    public function store(Request $request, Establecimiento $establecimiento, CrearBorrador $crear): JsonResponse
    {
        Gate::authorize('documentos.crear', $establecimiento);
        $d = $request->validate([
            'id' => ['required', Reglas::UUID], 'documento_id' => ['required', Reglas::UUID], 'familia' => ['required', 'string', 'in:'.implode(',', array_keys(Familias::TODAS))],
        ]);
        $tokenId = $request->user()->currentAccessToken()?->getKey();
        $dispositivo = $tokenId ? DB::table('dispositivos')->where('token_id', $tokenId)->value('id') : null;
        [$b, $creado] = $crear->handle($establecimiento, $request->user(), $d, $dispositivo);

        return response()->json(['data' => BorradorResource::make($b)], $creado ? 202 : 200);
    }

    public function show(Establecimiento $establecimiento, string $borrador): JsonResponse
    {
        Gate::authorize('documentos.ver', $establecimiento);

        return response()->json(['data' => BorradorResource::make($this->buscar($establecimiento, $borrador), true)]);
    }

    public function campos(Request $request, Establecimiento $establecimiento, string $borrador, RevisarCampos $revisar): JsonResponse
    {
        Gate::authorize('documentos.crear', $establecimiento);
        $d = $request->validate([
            'campos' => ['present', 'array', 'max:500'], 'campos.*.ruta' => ['required', 'string', 'max:100'],
            'campos.*.estado' => ['required', 'in:correcto,editado,no_legible,no_aplica'], 'campos.*.valor' => ['nullable'],
            'agregar_fila' => ['nullable', 'in:vacunas,categorias'], 'row_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $cambios = array_map(fn ($c) => array_intersect_key($c, array_flip(['ruta', 'estado', 'valor'])), $request->input('campos', []));
        $b = $revisar->handle($establecimiento, $request->user(), $this->buscar($establecimiento, $borrador), $cambios, $d['agregar_fila'] ?? null,
            isset($d['row_version']) ? (int) $d['row_version'] : null);

        return response()->json(['data' => BorradorResource::make($b)]);
    }

    public function confirmar(Request $request, Establecimiento $establecimiento, string $borrador, ConfirmarBorrador $confirmar): JsonResponse
    {
        Gate::authorize('documentos.crear', $establecimiento);
        $request->validate(['confirmar_advertencias' => ['nullable', 'boolean']]);
        $b = $confirmar->handle($establecimiento, $request->user(), $this->buscar($establecimiento, $borrador), $request->boolean('confirmar_advertencias'));

        return response()->json(['data' => BorradorResource::make($b, true)]);
    }

    public function reextraer(Establecimiento $establecimiento, string $borrador): JsonResponse
    {
        Gate::authorize('documentos.crear', $establecimiento);
        $b = $this->buscar($establecimiento, $borrador);
        $colgado = $b->estado === 'extrayendo' && now()->diffInMinutes($b->updated_at, true) > 15;
        if (! in_array($b->estado, ['fallido', 'para_revisar'], true) && ! $colgado) {
            throw new ApiException(409, 'estado_invalido', __('El borrador no se puede volver a leer ahora (:e).', ['e' => $b->estado]));
        }
        DB::table('borradores_documento')->where('id', $b->id)->update(['estado' => 'en_cola', 'intentos' => 0, 'error' => null, 'updated_at' => now()]);
        ExtraerBorradorJob::dispatch($b->id);

        return response()->json(['data' => BorradorResource::make($this->buscar($establecimiento, $borrador))], 202);
    }

    public function descartar(Request $request, Establecimiento $establecimiento, string $borrador, RegistrarAuditoria $auditoria): JsonResponse
    {
        Gate::authorize('documentos.crear', $establecimiento);
        $b = $this->buscar($establecimiento, $borrador);
        if ($b->estado === 'confirmado') {
            throw new ApiException(409, 'estado_invalido', __('Un borrador confirmado no se descarta: corregí los eventos.'));
        }
        if ($b->estado !== 'descartado') {
            DB::table('borradores_documento')->where('id', $b->id)->update(['estado' => 'descartado', 'descartado_por' => $request->user()->id, 'descartado_en' => now(),
                'row_version' => $b->row_version + 1, 'updated_at' => now()]);
            $auditoria->handle($establecimiento->id, $request->user()->id, 'borrador_documento', $b->id, 'descarte', ['estado' => $b->estado], ['estado' => 'descartado']);
        }

        return response()->json(['data' => BorradorResource::make($this->buscar($establecimiento, $borrador))]);
    }

    /** Borradores del establecimiento; los de documentos fiscales solo con `documentos.fiscal`. */
    private function visibles(Establecimiento $est): Builder
    {
        return DB::table('borradores_documento as b')->join('documentos as d', 'd.id', '=', 'b.documento_id')->where('b.establecimiento_id', $est->id)
            ->when(! Gate::allows('documentos.fiscal', $est), fn ($q) => $q->where('d.privacidad', '<>', 'fiscal'));
    }

    private function buscar(Establecimiento $est, string $id): object
    {
        $id = strtolower($id);
        $b = preg_match('/^[0-9a-f-]{36}$/', $id) ? $this->visibles($est)->where('b.id', $id)->select('b.*')->first() : null;

        return $b ?? throw ApiException::noEncontrado();
    }
}
