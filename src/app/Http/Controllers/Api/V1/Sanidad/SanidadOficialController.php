<?php

namespace App\Http\Controllers\Api\V1\Sanidad;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sanidad\Oficial\Actions\AnularProtocolo;
use App\Domain\Sanidad\Oficial\Actions\GuardarPlanSaneamiento;
use App\Domain\Sanidad\Oficial\Actions\RegistrarEstatus;
use App\Domain\Sanidad\Oficial\Actions\RegistrarProtocolo;
use App\Domain\Sanidad\Oficial\CondicionSanitaria;
use App\Domain\Sanidad\Oficial\ResumenVeterinario;
use App\Domain\Sanidad\Oficial\SanidadOficial;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Sanidad\EstatusSanitarioRequest;
use App\Http\Requests\V1\Sanidad\PlanSaneamientoRequest;
use App\Http\Requests\V1\Sanidad\ProtocoloRequest;
use App\Http\Resources\V1\CursorOffset;
use App\Http\Resources\V1\Sanidad\SanidadOficialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pruebas, certificados y estatus de brucelosis/TBC (TPA-66). Todo privado del establecimiento (`sanidad.ver`); la carga
 * es transcripción revisada (con original) o declaración del productor, nunca la condición oficial.
 */
class SanidadOficialController extends Controller
{
    public function resumen(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('sanidad.ver', $establecimiento);
        $d = $request->validate(['horizonte_dias' => ['sometimes', 'integer', 'min:1', 'max:365']]);

        return response()->json(['data' => CondicionSanitaria::resumen($establecimiento, (int) ($d['horizonte_dias'] ?? 90))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function exportar(Request $request, Establecimiento $establecimiento): Response
    {
        Gate::authorize('sanidad.ver', $establecimiento);
        $d = $request->validate(['formato' => ['sometimes', 'in:pdf,csv'], 'meses' => ['sometimes', 'integer', 'min:1', 'max:60']]);
        $meses = (int) ($d['meses'] ?? 24);
        $nombre = 'resumen-sanitario-'.Carbon::now($establecimiento->zona_horaria)->toDateString();
        if (($d['formato'] ?? 'pdf') === 'csv') {
            return response(ResumenVeterinario::csv($establecimiento, $meses), 200, [
                'Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"{$nombre}.csv\"", 'Cache-Control' => 'private, no-store',
            ]);
        }
        try {
            $pdf = ResumenVeterinario::pdf($establecimiento, $meses);
        } catch (RuntimeException) {
            throw new ApiException(503, 'servicio_no_disponible', __('No se pudo generar el PDF ahora. Probá de nuevo o bajá el CSV.'));
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$nombre}.pdf\"", 'Cache-Control' => 'private, no-store']);
    }

    public function protocolos(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('sanidad.ver', $establecimiento);
        $request->validate([
            'enfermedad' => ['sometimes', Rule::in(SanidadOficial::ENFERMEDADES)], 'motivo' => ['sometimes', Rule::in(SanidadOficial::MOTIVOS)],
            'estado' => ['sometimes', 'in:vigente,anulado,todos'],
        ]);
        $hoy = Carbon::now($establecimiento->zona_horaria)->toDateString();
        $aviso = SanidadOficial::diasAviso($establecimiento->id);
        $estado = $request->query('estado', 'vigente');
        $q = DB::table('protocolos_sanitarios')->where('establecimiento_id', $establecimiento->id)
            ->when($request->query('enfermedad'), fn ($q, $e) => $q->where('enfermedad', $e))
            ->when($request->query('motivo'), fn ($q, $m) => $q->where('motivo', $m))
            ->when($estado !== 'todos', fn ($q) => $q->where('estado', $estado))
            ->orderByDesc('fecha_muestreo')->orderByDesc('created_at')->orderByDesc('id');

        return CursorOffset::responder($q, $request, $request->only(['enfermedad', 'motivo', 'estado']),
            fn ($p) => SanidadOficialResource::protocolo($p, false, $hoy, $aviso));
    }

    public function crearProtocolo(ProtocoloRequest $request, Establecimiento $establecimiento, RegistrarProtocolo $registrar): JsonResponse
    {
        [$p, $creado] = $registrar->handle($establecimiento, $request->user(), $request->validated(), $request->boolean('confirmar_advertencias'));

        return response()->json(['data' => SanidadOficialResource::protocolo($p, true, Carbon::now($establecimiento->zona_horaria)->toDateString(),
            SanidadOficial::diasAviso($establecimiento->id))], $creado ? 201 : 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function protocolo(Establecimiento $establecimiento, string $protocolo): JsonResponse
    {
        Gate::authorize('sanidad.ver', $establecimiento);

        return response()->json(['data' => SanidadOficialResource::protocolo($this->buscar('protocolos_sanitarios', $establecimiento, $protocolo), true,
            Carbon::now($establecimiento->zona_horaria)->toDateString(), SanidadOficial::diasAviso($establecimiento->id))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function anularProtocolo(Request $request, Establecimiento $establecimiento, string $protocolo, AnularProtocolo $anular): JsonResponse
    {
        Gate::authorize('sanidad.corregir', $establecimiento);
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);
        $p = $anular->handle($establecimiento, $request->user(), $this->buscar('protocolos_sanitarios', $establecimiento, $protocolo), $d['motivo']);

        return response()->json(['data' => SanidadOficialResource::protocolo($p, true)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function crearEstatus(EstatusSanitarioRequest $request, Establecimiento $establecimiento, RegistrarEstatus $registrar): JsonResponse
    {
        [$e, $creado] = $registrar->handle($establecimiento, $request->user(), $request->validated());

        return response()->json(['data' => $this->estatusJson($establecimiento, $e)], $creado ? 201 : 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function anularEstatus(Request $request, Establecimiento $establecimiento, string $estatus, RegistrarEstatus $registrar): JsonResponse
    {
        Gate::authorize('sanidad.corregir', $establecimiento);
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);
        $e = $registrar->anular($establecimiento, $request->user(), $this->buscar('estatus_sanitarios', $establecimiento, $estatus), $d['motivo']);

        return response()->json(['data' => $this->estatusJson($establecimiento, $e)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function crearPlan(PlanSaneamientoRequest $request, Establecimiento $establecimiento, GuardarPlanSaneamiento $guardar): JsonResponse
    {
        [$p, $creado] = $guardar->crear($establecimiento, $request->user(), $request->validated());

        return response()->json(['data' => SanidadOficialResource::plan($p, Carbon::now($establecimiento->zona_horaria)->toDateString())], $creado ? 201 : 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function actualizarPlan(PlanSaneamientoRequest $request, Establecimiento $establecimiento, string $plan, GuardarPlanSaneamiento $guardar): JsonResponse
    {
        $p = $guardar->actualizar($establecimiento, $request->user(), $this->buscar('planes_saneamiento', $establecimiento, $plan), $request->validated());

        return response()->json(['data' => SanidadOficialResource::plan($p, Carbon::now($establecimiento->zona_horaria)->toDateString())], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Resultados de un animal en protocolos vigentes (ficha). */
    public function pruebasAnimal(Establecimiento $establecimiento, string $animal): JsonResponse
    {
        Gate::authorize('sanidad.ver', $establecimiento);
        if (! preg_match('/^[0-9a-f-]{36}$/', strtolower($animal)) || ! DB::table('animales')->where('id', strtolower($animal))->where('establecimiento_id', $establecimiento->id)->exists()) {
            throw ApiException::noEncontrado();
        }
        $filas = DB::table('protocolo_resultados as r')->join('protocolos_sanitarios as p', 'p.id', '=', 'r.protocolo_id')
            ->where('r.animal_id', strtolower($animal))->where('p.estado', 'vigente')->orderByDesc('p.fecha_muestreo')->orderByDesc('p.created_at')
            ->get(['r.*', 'p.enfermedad', 'p.prueba_codigo', 'p.motivo', 'p.fecha_muestreo', 'p.fecha_resultado', 'p.laboratorio', 'p.protocolo_numero', 'p.vence_el', 'p.origen']);

        return response()->json(['data' => $filas->map(fn ($r) => [
            'protocolo_id' => $r->protocolo_id, 'enfermedad' => $r->enfermedad, 'prueba' => $r->prueba_codigo, 'prueba_nombre' => SanidadOficialResource::nombrePrueba($r->prueba_codigo),
            'motivo' => $r->motivo, 'fecha_muestreo' => $r->fecha_muestreo, 'fecha_resultado' => $r->fecha_resultado, 'laboratorio' => $r->laboratorio,
            'protocolo_numero' => $r->protocolo_numero, 'vence_el' => $r->vence_el, 'origen' => $r->origen, 'origen_leyenda' => SanidadOficial::leyendaOrigen($r->origen),
            ...array_diff_key(SanidadOficialResource::resultado($r), ['animal' => 1]),
        ])->all()], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function estatusJson(Establecimiento $est, object $e): array
    {
        return SanidadOficialResource::estatus($e, Carbon::now($est->zona_horaria)->toDateString(), SanidadOficial::diasAviso($est->id));
    }

    private function buscar(string $tabla, Establecimiento $est, string $id): object
    {
        $id = strtolower($id);
        $fila = preg_match('/^[0-9a-f-]{36}$/', $id) ? DB::table($tabla)->where('id', $id)->where('establecimiento_id', $est->id)->first() : null;

        return $fila ?? throw ApiException::noEncontrado();
    }
}
