<?php

namespace App\Http\Controllers\Api\V1\Hacienda;

use App\Domain\Documentos\Actions\GuardarArchivo;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Importacion\ProcesarImportacion;
use App\Domain\Hacienda\Importacion\ValidadorFila;
use App\Domain\Hacienda\Importacion\ValidarImportacionJob;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CursorOffset;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Carga inicial de stock por planilla (TPA-21). */
class ImportacionController extends Controller
{
    /**
     * POST: `archivo_sha256` de un archivo ya subido **o** (desvío aditivo) multipart `archivo` con el CSV/XLSX.
     * Responde 202 y valida en cola.
     */
    public function store(Request $request, Establecimiento $establecimiento, GuardarArchivo $guardar): JsonResponse
    {
        Gate::authorize('importaciones.gestionar', $establecimiento);
        $request->validate([
            'archivo' => ['required_without:archivo_sha256', 'file', 'max:10240'],
            'archivo_sha256' => ['required_without:archivo', 'nullable', 'regex:/^[0-9a-f]{64}$/'],
            'formato' => ['nullable', 'in:csv,xlsx'],
            'especie_default' => ['nullable', 'exists:especies,codigo'],
            'mapeo' => ['nullable', 'array'],
        ]);
        $formato = $request->input('formato');
        if ($request->hasFile('archivo')) {
            $f = $request->file('archivo');
            $formato ??= strtolower($f->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
            $sha = $guardar->handle((string) file_get_contents($f->getRealPath()), $f->getMimeType() ?? 'text/csv', $request->user());
        } else {
            $sha = $request->input('archivo_sha256');
            if (! DB::table('archivos')->where('sha256', $sha)->where('estado', 'completo')->exists()) {
                throw ApiException::validacion(__('El archivo todavía no se subió.'), ['archivo_sha256' => [__('Archivo no disponible.')]]);
            }
        }
        $id = (string) Str::uuid7();
        DB::table('importaciones')->insert([
            'id' => $id, 'establecimiento_id' => $establecimiento->id, 'archivo_sha256' => $sha, 'formato' => $formato ?? 'csv',
            'estado' => 'subida', 'especie_default' => $request->input('especie_default'), 'mapeo' => json_encode((object) ($request->input('mapeo') ?? [])),
            'creado_por' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        ValidarImportacionJob::dispatch($id);

        return response()->json(['data' => $this->importacion($this->buscar($establecimiento, $id))], 202);
    }

    public function show(Establecimiento $establecimiento, string $importacion): JsonResponse
    {
        Gate::authorize('importaciones.gestionar', $establecimiento);

        return response()->json(['data' => $this->importacion($this->buscar($establecimiento, $importacion))]);
    }

    public function filas(Request $request, Establecimiento $establecimiento, string $importacion): JsonResponse
    {
        Gate::authorize('importaciones.gestionar', $establecimiento);
        $imp = $this->buscar($establecimiento, $importacion);
        $q = DB::table('importacion_filas')->where('importacion_id', $imp->id)
            ->when($request->boolean('con_errores'), fn ($q) => $q->whereRaw("errores <> '[]'::jsonb"))->orderBy('numero');

        return CursorOffset::responder($q, $request, $request->only('con_errores'), fn ($f) => $this->fila($f));
    }

    public function corregirFila(Request $request, Establecimiento $establecimiento, string $importacion, int $numero, ProcesarImportacion $procesar): JsonResponse
    {
        Gate::authorize('importaciones.gestionar', $establecimiento);
        $request->validate(['accion' => ['nullable', 'in:importar,descartar'], 'datos' => ['nullable', 'array']]);

        return response()->json(['data' => $this->fila($procesar->corregirFila($this->buscar($establecimiento, $importacion), $numero, $request->input('datos'), $request->input('accion')))]);
    }

    public function confirmar(Request $request, Establecimiento $establecimiento, string $importacion): JsonResponse
    {
        Gate::authorize('importaciones.gestionar', $establecimiento);
        $imp = $this->buscar($establecimiento, $importacion);
        if (! in_array($imp->estado, ['validada', 'con_errores', 'confirmada'], true)) {
            throw ApiException::validacion(__('La importación no está lista para confirmar.'), ['estado' => [$imp->estado]]);
        }
        if ($imp->filas_validas === 0) {
            throw ApiException::validacion(__('No hay filas válidas para importar.'), ['filas' => [__('Corregí o descartá las filas con errores.')]]);
        }
        ValidarImportacionJob::dispatch($imp->id, $request->user()->id);

        return response()->json(['data' => $this->importacion($this->buscar($establecimiento, $imp->id))], 202);
    }

    public function cancelar(Establecimiento $establecimiento, string $importacion): JsonResponse
    {
        Gate::authorize('importaciones.gestionar', $establecimiento);
        $imp = $this->buscar($establecimiento, $importacion);
        if ($imp->estado === 'confirmada') {
            throw ApiException::reglaBloqueante(__('Una importación confirmada no se cancela: anulá los eventos.'));
        }
        DB::table('importaciones')->where('id', $imp->id)->update(['estado' => 'cancelada', 'updated_at' => now()]);

        return response()->json(['data' => $this->importacion($this->buscar($establecimiento, $imp->id))]);
    }

    /** GET /importaciones/plantilla?especie&formato=csv: plantilla con encabezados y una fila de ejemplo. */
    public function plantilla(Request $request): Response
    {
        $especie = $request->query('especie') === 'BOV' ? 'BOV' : 'BUB';
        $ejemplo = $especie === 'BUB'
            ? ['BUB', 'H', 'Búfala', 'Murrah', '0142', '', '032000123456789', 'U-0142', '', '2020-09-20', 'si', '', '6+', 'Búfalas Murrah', 'Bañado Norte', '']
            : ['BOV', 'H', 'Vaca', 'Braford', '1187', '', '', '', '', '2019-10-01', 'si', '', '', 'Vacas de cría', 'Loma del Monte', ''];
        $csv = implode(',', ValidadorFila::COLUMNAS)."\n".implode(',', array_map(fn ($v) => str_contains($v, ',') ? "\"{$v}\"" : $v, $ejemplo))."\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"plantilla-stock-{$especie}.csv\""]);
    }

    private function buscar(Establecimiento $est, string $id): object
    {
        return DB::table('importaciones')->where('id', $id)->where('establecimiento_id', $est->id)->first() ?? throw ApiException::noEncontrado();
    }

    /** @return array<string, mixed> */
    private function importacion(object $i): array
    {
        return [
            'id' => $i->id, 'estado' => $i->estado, 'filas_total' => (int) $i->filas_total, 'filas_validas' => (int) $i->filas_validas,
            'filas_error' => (int) $i->filas_error, 'mapeo' => (object) json_decode($i->mapeo, true),
            'created_at' => Formato::fechaHora(Carbon::parse($i->created_at)),
            'confirmada_en' => $i->confirmada_en ? Formato::fechaHora(Carbon::parse($i->confirmada_en)) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function fila(object $f): array
    {
        return ['numero' => (int) $f->numero, 'datos' => (object) json_decode($f->datos, true), 'errores' => json_decode($f->errores, true),
            'advertencias' => json_decode($f->advertencias, true), 'accion' => $f->accion];
    }
}
