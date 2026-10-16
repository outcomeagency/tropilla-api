<?php

namespace App\Http\Controllers\Api\V1\Hacienda;

use App\Domain\Documentos\ConsultasDocumentos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Actions\AltaRapida;
use App\Domain\Hacienda\Actions\ConfirmarRecategorizaciones;
use App\Domain\Hacienda\Actions\DescartarSugerencias;
use App\Domain\Hacienda\ConsultasAnimales;
use App\Domain\Hacienda\Eventos\ConsultasEventos;
use App\Domain\Hacienda\Proyecciones\Identificaciones;
use App\Domain\Hacienda\SeriePesos;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Hacienda\AltaRapidaRequest;
use App\Http\Resources\V1\CursorOffset;
use App\Http\Resources\V1\CursorRespuesta;
use App\Http\Resources\V1\Hacienda\AnimalResource;
use App\Http\Resources\V1\Hacienda\AnimalResumenResource;
use App\Http\Resources\V1\Hacienda\EventoResumenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Animales (TPA-16 alta rápida, TPA-17 ficha, TPA-18 listado, TPA-19 identificador). */
class AnimalController extends Controller
{
    /**
     * Listado (TPA-18): búsqueda por cualquier identificador (parcial, normalizado, índice trigram) y filtros
     * especie, categoria, lote_id, potrero_id, estado (default activo; `todos`), estado_reproductivo, sexo,
     * sanidad (retiro|reactor), sugerencia_categoria; orden: identificacion|categoria|peso|edad|reciente.
     */
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $f = $request->only(['q', 'especie', 'categoria', 'lote_id', 'potrero_id', 'estado', 'estado_reproductivo', 'sexo', 'sanidad', 'sugerencia_categoria', 'orden']);
        $q = ConsultasAnimales::listado($establecimiento->id, $f);

        return CursorOffset::responder($q, $request, $f, fn ($fila) => AnimalResumenResource::campos($fila));
    }

    public function store(AltaRapidaRequest $request, Establecimiento $establecimiento, AltaRapida $alta): JsonResponse
    {
        Gate::authorize('hacienda.crear', $establecimiento);
        $id = $alta->handle($establecimiento, $request->user(), EventoController::cuerpo($request));

        return AnimalResource::make(ConsultasAnimales::uno($establecimiento->id, $id))->response()->setStatusCode(201);
    }

    /** Confirmar recategorizaciones sugeridas en bloque (TPA-25): un evento por animal. */
    public function recategorizar(Request $request, Establecimiento $establecimiento, ConfirmarRecategorizaciones $confirmar): JsonResponse
    {
        Gate::authorize('hacienda.crear', $establecimiento);
        $datos = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.animal_id' => ['required', 'uuid', 'distinct'],
            'items.*.categoria' => ['required', 'string', 'max:64'],
            'ocurrido_en' => ['nullable', 'regex:/^\d{4}-\d{2}-\d{2}$/'],
        ]);
        $eventos = $confirmar->handle($establecimiento, $request->user(), $datos['items'], $datos['ocurrido_en'] ?? null);
        $filas = ConsultasEventos::base()->whereIn('v.id', array_map(fn ($e) => $e->id, $eventos))->get();

        return response()->json(['data' => EventoResumenResource::collection($filas)->resolve($request)], 201);
    }

    public function show(Establecimiento $establecimiento, string $animal): AnimalResource
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $fila = ConsultasAnimales::uno($establecimiento->id, $animal) ?? throw ApiException::noEncontrado();

        return AnimalResource::make($fila);
    }

    /**
     * Identificador exacto (chequeo de duplicado antes del alta): vigentes del establecimiento. Tolera ceros a la izquierda
     * (bugfix TPA-43, pedido por la app: el bastón/teclado no siempre los carga): «142» encuentra «0142».
     */
    public function identificador(Request $request, Establecimiento $establecimiento): AnonymousResourceCollection
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $valor = Identificaciones::normalizar((string) $request->query('valor', ''));

        $ids = $valor === '' ? [] : DB::table('animal_identificaciones')->where('establecimiento_id', $establecimiento->id)
            ->whereRaw(Identificaciones::SQL_SIN_CEROS.' = ?', [Identificaciones::sinCeros($valor)])->where('vigente', true)
            ->when($request->query('tipo'), fn ($q, $t) => $q->where('tipo', $t))
            ->distinct()->pluck('animal_id')->all();

        return AnimalResumenResource::collection(ConsultasAnimales::base($establecimiento->id)->whereIn('a.animal_id', $ids)->get());
    }

    /** Timeline (TPA-17): incluye eventos heredados del lote anónimo · filtros tipo, modulo, incluir_anulados. */
    public function eventos(Request $request, Establecimiento $establecimiento, string $animal): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $a = DB::table('animales')->where('id', $animal)->where('establecimiento_id', $establecimiento->id)->first() ?? throw ApiException::noEncontrado();
        $q = ConsultasEventos::timelineAnimal($a, $request->only(['tipo', 'modulo', 'incluir_anulados']));

        return CursorRespuesta::make($q->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor'), EventoResumenResource::class, $request);
    }

    /** Descartar sugerencias de recategorización en bloque (bugfix TPA-25): no se vuelven a proponer para ese animal. */
    public function descartarSugerencias(Request $request, Establecimiento $establecimiento, DescartarSugerencias $descartar): JsonResponse
    {
        Gate::authorize('hacienda.crear', $establecimiento);
        $datos = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.animal_id' => ['required', 'uuid', 'distinct'],
            'items.*.categoria' => ['required', 'string', 'max:64'],
        ]);
        $ids = $descartar->handle($establecimiento, $request->user(), $datos['items']);
        $filas = ConsultasAnimales::base($establecimiento->id)->whereIn('a.animal_id', $ids)->get();

        return response()->json(['data' => AnimalResumenResource::collection($filas)->resolve($request)]);
    }

    /** Serie de pesos para la curva de la ficha (bugfix TPA-23): ascendente por fecha, desde el payload de los eventos. */
    public function pesajes(Request $request, Establecimiento $establecimiento, string $animal): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $a = DB::table('animales')->where('id', $animal)->where('establecimiento_id', $establecimiento->id)->first() ?? throw ApiException::noEncontrado();
        $f = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'incluir_lote' => ['nullable', 'in:true,false,1,0'],
        ]);

        return response()->json(['data' => SeriePesos::de($a, $establecimiento->zona_horaria, [
            'desde' => $f['desde'] ?? null, 'hasta' => $f['hasta'] ?? null, 'incluir_lote' => ! $request->has('incluir_lote') || $request->boolean('incluir_lote'),
        ])]);
    }

    /** Genealogía: madre, padre (del sistema o externos) y crías. */
    public function genealogia(Establecimiento $establecimiento, string $animal): JsonResponse
    {
        Gate::authorize('hacienda.ver', $establecimiento);
        $a = ConsultasAnimales::uno($establecimiento->id, $animal) ?? throw ApiException::noEncontrado();
        $pariente = function (?string $id, ?string $externo) use ($establecimiento) {
            if ($id && ($fila = ConsultasAnimales::uno($establecimiento->id, $id))) {
                return AnimalResumenResource::campos($fila);
            }

            return $externo ? ['externo' => $externo] : null;
        };
        $crias = ConsultasAnimales::base($establecimiento->id)
            ->where(fn ($q) => $q->where('a.madre_id', $animal)->orWhere('a.padre_id', $animal))
            ->orderBy('a.fecha_nacimiento')->get();

        return response()->json(['data' => [
            'animal' => AnimalResumenResource::campos($a),
            'madre' => $pariente($a->madre_id, $a->madre_externa),
            'padre' => $pariente($a->padre_id, $a->padre_externo),
            'crias' => $crias->map(fn ($c) => AnimalResumenResource::campos($c))->all(),
        ]]);
    }

    /** Documentos vinculados al animal (la carpeta documental llega con TPA-36). */
    public function documentos(Establecimiento $establecimiento, string $animal): JsonResponse
    {
        Gate::authorize('documentos.ver', $establecimiento);
        DB::table('animales')->where('id', $animal)->where('establecimiento_id', $establecimiento->id)->exists() || throw ApiException::noEncontrado();
        $hoy = Carbon::now($establecimiento->zona_horaria)->toDateString();
        $docs = ConsultasDocumentos::base($establecimiento->id, Gate::allows('documentos.fiscal', $establecimiento))
            ->whereExists(fn ($w) => $w->from('documento_vinculos as v')->whereColumn('v.documento_id', 'd.id')->where('v.entidad_tipo', 'animal')->where('v.entidad_id', $animal))
            ->orderByDesc('d.created_at')->get(['d.*']);

        return response()->json(['data' => $docs->map(fn ($d) => ConsultasDocumentos::documento($d, $hoy))->all()], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
