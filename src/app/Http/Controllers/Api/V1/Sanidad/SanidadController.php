<?php

namespace App\Http\Controllers\Api\V1\Sanidad;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\ConsultasAnimales;
use App\Domain\Sanidad\LibroTratamientos;
use App\Domain\Sanidad\Retiros;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Sanidad\SanidadListaRequest;
use App\Http\Resources\V1\CursorOffset;
use App\Http\Resources\V1\Hacienda\AnimalResumenResource;
use App\Rules\Reglas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Listas destacadas de Sanidad (TPA-35): retiro vigente y reactores. Datos **privados** del establecimiento: estas listas no
 * alimentan nada público (perfil y ofertas usan su propia whitelist y solo muestran hechos agregados por acción explícita).
 */
class SanidadController extends Controller
{
    public function retiros(SanidadListaRequest $request, Establecimiento $establecimiento): JsonResponse
    {
        $hoy = Carbon::now($establecimiento->zona_horaria)->toDateString();
        $especie = $request->validated('especie');
        $animales = ConsultasAnimales::base($establecimiento->id)->where('a.estado', 'activo')
            ->when($especie, fn ($q) => $q->where('a.especie_codigo', $especie))
            ->where(fn ($w) => $w->where('a.retiro_carne_hasta', '>=', $hoy)->orWhere('a.retiro_leche_hasta', '>=', $hoy))
            ->orderByRaw('GREATEST(a.retiro_carne_hasta, a.retiro_leche_hasta) DESC')->orderBy('a.animal_id')->get();

        // Aditivo: lotes anónimos con retiro vigente (no tienen filas por animal).
        $lotes = DB::table('lotes as l')->leftJoin('lotes_estado as le', 'le.lote_id', '=', 'l.id')
            ->where('l.establecimiento_id', $establecimiento->id)->where('l.anonimo', true)->whereNull('l.archivado_en')
            ->when($especie, fn ($q) => $q->where('l.especie_codigo', $especie))->get(['l.id', 'l.nombre', 'l.especie_codigo', 'le.cabezas_anonimas'])
            ->map(fn ($l) => ['id' => $l->id, 'nombre' => $l->nombre, 'especie' => $l->especie_codigo, 'cabezas' => (int) $l->cabezas_anonimas,
                'retiro_carne_hasta' => ($r = Retiros::deLote($l->id))['carne'], 'retiro_leche_hasta' => $r['leche']])
            ->filter(fn ($l) => ($l['retiro_carne_hasta'] ?? '') >= $hoy || ($l['retiro_leche_hasta'] ?? '') >= $hoy)->values()->all();

        return response()->json(['data' => AnimalResumenResource::collection($animales)->resolve(), 'lotes' => $lotes], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Libro de tratamientos de TROPILLA (TPA-65): buscable, con documentos fuente. No es el libro oficial. */
    public function libroTratamientos(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('sanidad.ver', $establecimiento);
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d'],
            'tipo' => ['nullable', 'in:'.implode(',', array_keys(LibroTratamientos::TIPOS))], 'lote_id' => ['nullable', Reglas::UUID],
        ]);
        $verDocs = Gate::allows('documentos.ver', $establecimiento);
        $verFiscales = Gate::allows('documentos.fiscal', $establecimiento);
        $respuesta = CursorOffset::responder(LibroTratamientos::consulta($establecimiento->id, $f), $request, $f, fn ($e) => $e);
        $cuerpo = $respuesta->getData(true);
        $docs = $verDocs ? LibroTratamientos::documentos(array_column($cuerpo['data'], 'evento_raiz_id'), $verFiscales) : [];
        $cuerpo['data'] = array_map(fn ($e) => LibroTratamientos::fila((object) $e, $docs), $cuerpo['data']);
        $cuerpo['aviso'] = __(LibroTratamientos::AVISO);

        return response()->json($cuerpo, 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function reactores(SanidadListaRequest $request, Establecimiento $establecimiento): JsonResponse
    {
        $especie = $request->validated('especie');
        $animales = ConsultasAnimales::base($establecimiento->id)->where('a.estado', 'activo')->where('a.reactor', true)
            ->when($especie, fn ($q) => $q->where('a.especie_codigo', $especie))
            ->orderByDesc('a.reactor_desde')->orderBy('a.animal_id')->get();

        return response()->json(['data' => AnimalResumenResource::collection($animales)->resolve()], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
