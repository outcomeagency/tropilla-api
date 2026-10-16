<?php

namespace App\Http\Controllers\Api\V1\Hacienda;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\ConsultasEventos;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Integridad\CanonicalizacionInvalida;
use App\Domain\Integridad\CanonicalJson;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hacienda\EventoRequest;
use App\Http\Resources\V1\CursorRespuesta;
use App\Http\Resources\V1\Hacienda\EventoResource;
use App\Http\Resources\V1\Hacienda\EventoResumenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use stdClass;

/** Eventos por REST (web): mismo camino que el push (RegistrarEvento). TPA-22/26. */
class EventoController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);

        $q = ConsultasEventos::actividad($establecimiento->id, $request->only(['tipo', 'modulo', 'desde', 'hasta', 'autor_id', 'lote_id']));

        return CursorRespuesta::make($q->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor'), EventoResumenResource::class, $request);
    }

    public function store(EventoRequest $request, Establecimiento $establecimiento, RegistrarEvento $registrar): JsonResponse
    {
        Gate::authorize('establecimiento.ver', $establecimiento);
        $in = self::cuerpo($request);

        // Idempotente por id: un reintento del mismo POST devuelve el evento ya registrado.
        if (isset($in->id) && ($existente = Evento::query()->where('id', strtolower($in->id))->where('establecimiento_id', $establecimiento->id)->first())) {
            return EventoResource::make($existente)->response()->setStatusCode(200);
        }

        $resultado = $registrar->handle($establecimiento, $request->user(), ArmarSobreWeb::alta($establecimiento, $request->user(), $in), [
            'canal' => 'web', 'confirmar_advertencias' => (bool) ($in->confirmar_advertencias ?? false),
        ]);

        return EventoResource::make($resultado->evento)->response()->setStatusCode(201);
    }

    /** Cuerpo crudo decodificado con objetos (`{}` ≠ `[]`). */
    public static function cuerpo(Request $request): stdClass
    {
        try {
            $in = CanonicalJson::decode($request->getContent() ?: '{}');
        } catch (CanonicalizacionInvalida $e) {
            throw new ApiException(400, 'request_invalido', __('JSON mal formado.'));
        }
        if (! $in instanceof stdClass) {
            throw new ApiException(400, 'request_invalido', __('Se espera un objeto JSON.'));
        }

        return $in;
    }
}
