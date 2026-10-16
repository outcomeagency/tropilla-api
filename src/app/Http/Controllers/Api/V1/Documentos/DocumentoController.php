<?php

namespace App\Http\Controllers\Api\V1\Documentos;

use App\Domain\Documentos\Actions\GestionarEnlace;
use App\Domain\Documentos\Actions\GestionarVisibilidad;
use App\Domain\Documentos\Actions\RegistrarDocumentoVersion;
use App\Domain\Documentos\ArmarSobreDocumentoWeb;
use App\Domain\Documentos\ConsultasDocumentos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Documentos\DocumentoRequest;
use App\Http\Requests\V1\Documentos\DocumentoVersionRequest;
use App\Http\Resources\V1\CursorRespuesta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Carpeta documental (TPA-36): versiones inmutables por la única puerta `RegistrarDocumentoVersion`. */
class DocumentoController extends Controller
{
    public function index(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('documentos.ver', $establecimiento);
        $f = $request->validate([
            'tipo' => ['sometimes', 'string'], 'privacidad' => ['sometimes', 'in:fiscal,sanitario,comercial,general'],
            'vinculo_tipo' => ['sometimes', 'required_with:vinculo_id', 'string'], 'vinculo_id' => ['sometimes', 'uuid'],
            'vence_antes' => ['sometimes', 'date_format:Y-m-d'], 'visibilidad' => ['sometimes', 'in:privado,perfil'],
            'estado' => ['sometimes', 'in:vigente,vencido,revocado'], 'q' => ['sometimes', 'string', 'max:100'],
        ]);
        $hoy = $this->hoy($establecimiento);
        $q = ConsultasDocumentos::base($establecimiento->id, $this->verFiscal($request, $establecimiento))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('d.tipo_codigo', $v))
            ->when($f['privacidad'] ?? null, fn ($q, $v) => $q->where('d.privacidad', $v))
            ->when($f['visibilidad'] ?? null, fn ($q, $v) => $q->where('d.visibilidad', $v))
            ->when($f['vence_antes'] ?? null, fn ($q, $v) => $q->whereNotNull('d.vence_en')->where('d.vence_en', '<=', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->whereRaw('unaccent(d.titulo) ILIKE unaccent(?)', ['%'.addcslashes($v, '%_\\').'%']))
            ->when(isset($f['vinculo_id']), fn ($q) => $q->whereExists(fn ($w) => $w->from('documento_vinculos as v')->whereColumn('v.documento_id', 'd.id')
                ->where('v.entidad_tipo', $f['vinculo_tipo'])->where('v.entidad_id', $f['vinculo_id'])))
            ->when(($f['estado'] ?? null) === 'revocado', fn ($q) => $q->where('d.estado', 'revocado'))
            ->when(($f['estado'] ?? null) === 'vencido', fn ($q) => $q->where('d.estado', 'vigente')->where('d.vence_en', '<', $hoy))
            ->when(($f['estado'] ?? null) === 'vigente', fn ($q) => $q->where('d.estado', 'vigente')->where(fn ($w) => $w->whereNull('d.vence_en')->orWhere('d.vence_en', '>=', $hoy)))
            ->select('d.*')->orderByDesc('d.created_at')->orderByDesc('d.id');

        $pagina = $q->cursorPaginate(CursorRespuesta::limite($request), cursorName: 'cursor');

        return response()->json([
            'data' => $pagina->getCollection()->map(fn ($d) => ConsultasDocumentos::documento($d, $hoy))->values()->all(),
            'meta' => ['next_cursor' => $pagina->nextCursor()?->encode(), 'prev_cursor' => $pagina->previousCursor()?->encode(), 'limit' => $pagina->perPage()],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(DocumentoRequest $request, Establecimiento $establecimiento, RegistrarDocumentoVersion $registrar): JsonResponse
    {
        $d = $request->validated();
        if (! empty($d['id']) && ($existente = DB::table('documentos')->where('id', strtolower($d['id']))->where('establecimiento_id', $establecimiento->id)->first())) {
            return $this->respuesta($request, $establecimiento, $existente->id, 200); // reintento del mismo POST
        }
        $r = $registrar->handle($establecimiento, $request->user(), ArmarSobreDocumentoWeb::alta($establecimiento, $request->user(), $d), ['canal' => 'web']);

        return $this->respuesta($request, $establecimiento, $r->documentoId, 201);
    }

    public function vencimientos(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('documentos.ver', $establecimiento);
        $dias = (int) ($request->validate(['dias' => ['sometimes', 'integer', 'min:0', 'max:365']])['dias'] ?? 30);
        $hoy = $this->hoy($establecimiento);
        $limite = Carbon::parse($hoy)->addDays($dias)->toDateString();
        $docs = ConsultasDocumentos::base($establecimiento->id, $this->verFiscal($request, $establecimiento))
            ->where('d.estado', 'vigente')->whereNotNull('d.vence_en')->where('d.vence_en', '<=', $limite)
            ->orderBy('d.vence_en')->orderBy('d.id')->get(['d.*']);

        return response()->json(['data' => $docs->map(fn ($d) => ConsultasDocumentos::documento($d, $hoy))->all()], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(Request $request, Establecimiento $establecimiento, string $documento): JsonResponse
    {
        Gate::authorize('documentos.ver', $establecimiento);

        return $this->respuesta($request, $establecimiento, $this->buscar($request, $establecimiento, $documento)->id, 200);
    }

    public function versiones(DocumentoVersionRequest $request, Establecimiento $establecimiento, string $documento, RegistrarDocumentoVersion $registrar): JsonResponse
    {
        $doc = $this->buscar($request, $establecimiento, $documento);
        if ($doc->estado === 'revocado') {
            throw ApiException::reglaBloqueante(__('El documento está revocado.'));
        }
        $d = $request->validated();
        if (! empty($d['id']) && DB::table('documento_versiones')->where('id', strtolower($d['id']))->where('documento_id', $doc->id)->exists()) {
            return $this->respuesta($request, $establecimiento, $doc->id, 200);
        }
        $registrar->handle($establecimiento, $request->user(), ArmarSobreDocumentoWeb::nuevaVersion($doc, $request->user(), $d), ['canal' => 'web']);

        return $this->respuesta($request, $establecimiento, $doc->id, 201);
    }

    public function visibilidad(Request $request, Establecimiento $establecimiento, string $documento, GestionarVisibilidad $gestionar): JsonResponse
    {
        Gate::authorize('documentos.publicar', $establecimiento);
        $d = $request->validate(['visibilidad' => ['required', 'in:privado,perfil'], 'row_version' => ['required', 'integer', 'min:1']]);
        $doc = $this->buscar($request, $establecimiento, $documento);
        $gestionar->handle($establecimiento, $doc, $request->user(), $d['visibilidad'], (int) $d['row_version']);

        return $this->respuesta($request, $establecimiento, $doc->id, 200);
    }

    public function enlaces(Request $request, Establecimiento $establecimiento, string $documento): JsonResponse
    {
        Gate::authorize('documentos.compartir', $establecimiento);
        $doc = $this->buscar($request, $establecimiento, $documento);
        $enlaces = DB::table('enlaces_compartidos')->where('documento_id', $doc->id)->orderByDesc('created_at')->get();

        return response()->json(['data' => $enlaces->map(fn ($e) => GestionarEnlace::enlace($e))->all()]);
    }

    public function crearEnlace(Request $request, Establecimiento $establecimiento, string $documento, GestionarEnlace $gestionar): JsonResponse
    {
        Gate::authorize('documentos.compartir', $establecimiento);
        $d = $request->validate(['expira_en' => ['required', 'date_format:Y-m-d\TH:i:s.v\Z'], 'version' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $doc = $this->buscar($request, $establecimiento, $documento);

        return response()->json(['data' => $gestionar->crear($doc, $request->user(), Carbon::parse($d['expira_en']), $d['version'] ?? null)], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function revocarEnlace(Request $request, Establecimiento $establecimiento, string $documento, string $enlace, GestionarEnlace $gestionar): JsonResponse
    {
        Gate::authorize('documentos.compartir', $establecimiento);
        $doc = $this->buscar($request, $establecimiento, $documento);
        $e = DB::table('enlaces_compartidos')->where('id', strtolower($enlace))->where('documento_id', $doc->id)->first() ?? throw ApiException::noEncontrado();
        $gestionar->revocar($e, $request->user());

        return response()->json(null, 204);
    }

    private function respuesta(Request $request, Establecimiento $est, string $id, int $status): JsonResponse
    {
        $d = DB::table('documentos')->where('id', $id)->first();

        return response()->json(['data' => ConsultasDocumentos::documento($d, $this->hoy($est), true)], $status, [], JSON_UNESCAPED_UNICODE);
    }

    private function buscar(Request $request, Establecimiento $est, string $id): object
    {
        return ConsultasDocumentos::base($est->id, $this->verFiscal($request, $est))->where('d.id', strtolower($id))->first(['d.*'])
            ?? throw ApiException::noEncontrado();
    }

    private function verFiscal(Request $request, Establecimiento $est): bool
    {
        return Gate::forUser($request->user())->allows('documentos.fiscal', $est);
    }

    private function hoy(Establecimiento $est): string
    {
        return Carbon::now($est->zona_horaria)->toDateString();
    }
}
