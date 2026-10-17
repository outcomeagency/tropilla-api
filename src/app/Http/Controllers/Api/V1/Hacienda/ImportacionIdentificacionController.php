<?php

namespace App\Http\Controllers\Api\V1\Hacienda;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Identificacion\GuardarImportacionIdentificaciones;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hacienda\ImportacionIdentificacionesRequest;
use App\Http\Resources\V1\CursorOffset;
use App\Http\Resources\V1\Hacienda\ImportacionIdentificacionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Importaciones de identificaciones electrónicas desde lector, archivo o planilla de caravanas (TPA-63). */
class ImportacionIdentificacionController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $request->validate(['estado' => ['nullable', 'in:abierta,confirmada'], 'especie' => ['nullable', 'in:BOV,BUB']]);
        $q = DB::table('identificacion_importaciones')->where('establecimiento_id', $establecimiento->id)
            ->when($request->query('estado'), fn ($q, $e) => $q->where('estado', $e))
            ->when($request->query('especie'), fn ($q, $e) => $q->where('especie_codigo', $e))
            ->orderByDesc('created_at')->orderByDesc('id');

        return CursorOffset::responder($q, $request, $request->only(['estado', 'especie']), fn ($i) => ImportacionIdentificacionResource::make($i));
    }

    public function show(Establecimiento $establecimiento, string $importacion): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);

        return response()->json(['data' => ImportacionIdentificacionResource::make($this->buscar($establecimiento, $importacion), true)]);
    }

    /** PUT idempotente por id (UUIDv7 del teléfono): 201 al crearla, 200 al actualizarla. */
    public function update(ImportacionIdentificacionesRequest $request, Establecimiento $establecimiento, string $importacion, GuardarImportacionIdentificaciones $guardar): JsonResponse
    {
        Gate::authorize('hacienda.crear', $establecimiento);
        $tokenId = $request->user()->currentAccessToken()?->getKey();
        $dispositivo = $tokenId ? DB::table('dispositivos')->where('token_id', $tokenId)->value('id') : null;
        [$imp, $creada] = $guardar->handle($establecimiento, $request->user(), $importacion, $request->validated(), $dispositivo);

        return response()->json(['data' => ImportacionIdentificacionResource::make($imp, true)], $creada ? 201 : 200);
    }

    private function buscar(Establecimiento $est, string $id): object
    {
        return DB::table('identificacion_importaciones')->where('id', $id)->where('establecimiento_id', $est->id)->first() ?? throw ApiException::noEncontrado();
    }
}
