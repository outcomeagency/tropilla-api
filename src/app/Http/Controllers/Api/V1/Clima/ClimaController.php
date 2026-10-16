<?php

namespace App\Http\Controllers\Api\V1\Clima;

use App\Domain\Clima\Actions\ActualizarClima;
use App\Domain\Clima\EvaluadorAlertas;
use App\Domain\Clima\FuentesClima;
use App\Domain\Clima\ReglasPredeterminadas;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Formato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Alertas climáticas por establecimiento (TPA-70). Lectura para todo miembro (`establecimiento.ver`); si el último cálculo
 * tiene más de `vigencia_horas` se recalcula en el momento (las fuentes van con cache). La ubicación exacta nunca se devuelve.
 */
final class ClimaController extends Controller
{
    public function show(Establecimiento $establecimiento, ActualizarClima $actualizar): JsonResponse
    {
        Gate::authorize('establecimiento.ver', $establecimiento);
        $estado = DB::table('clima_estado')->where('establecimiento_id', $establecimiento->id)->first();
        if ($estado === null || Carbon::parse($estado->calculado_en)->lt(now()->subHours((int) config('tropilla.clima.vigencia_horas')))) {
            $actualizar->handle($establecimiento);
            $estado = DB::table('clima_estado')->where('establecimiento_id', $establecimiento->id)->first();
        }
        $hoy = Carbon::now($establecimiento->zona_horaria)->toDateString();
        $alertas = DB::table('clima_alertas')->where('establecimiento_id', $establecimiento->id)->where('estado', 'activa')->where('hasta', '>=', $hoy)
            ->orderByRaw("CASE severidad WHEN 'alta' THEN 0 WHEN 'moderada' THEN 1 ELSE 2 END")->orderBy('desde')->get();

        return response()->json(['data' => [
            'zona' => $estado->zona,
            'precision' => $estado->precision,
            'calculado_en' => Formato::fechaHora(Carbon::parse($estado->calculado_en)),
            'aviso' => EvaluadorAlertas::AVISO.' Las alertas se basan en modelos y reanálisis para la zona, no en mediciones de tu campo. Contrastalas con lo que ves y con tu asesor.',
            'alertas' => $alertas->map(fn ($a) => self::alerta($a))->all(),
            'pronostico' => array_values(array_filter(json_decode($estado->pronostico, true) ?? [], fn ($d) => $d['fecha'] >= $hoy)),
            'lluvia' => $estado->lluvia ? json_decode($estado->lluvia, true) : null,
            'enso' => $estado->enso ? json_decode($estado->enso, true) + ['fuente' => FuentesClima::FUENTES['noaa_cpc_oni']['nombre'], 'fuente_url' => FuentesClima::FUENTES['noaa_cpc_oni']['url']] : null,
            'fuentes' => array_values(FuentesClima::FUENTES),
            'errores' => json_decode($estado->errores, true) ?? [],
        ]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function reglas(Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('establecimiento.ver', $establecimiento);

        return response()->json(['data' => ActualizarClima::reglasEfectivas($establecimiento->id, true)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Override por establecimiento de umbrales, severidad o activación de una regla (dueño/admin). Recalcula al guardar. */
    public function actualizarRegla(Request $request, Establecimiento $establecimiento, string $codigo, ActualizarClima $actualizar): JsonResponse
    {
        Gate::authorize('establecimiento.editar', $establecimiento);
        $global = DB::table('clima_reglas')->where('codigo', $codigo)->whereNull('establecimiento_id')->first() ?? throw ApiException::noEncontrado();
        $claves = ReglasPredeterminadas::parametros($codigo) ?: array_keys(json_decode($global->parametros, true));
        $datos = $request->validate([
            'activa' => ['sometimes', 'boolean'],
            'severidad' => ['sometimes', 'in:info,moderada,alta'],
            'parametros' => ['sometimes', 'array:'.implode(',', $claves)],
            'parametros.*' => ['numeric', 'between:-100,10000'],
            'parametros.horizonte_dias' => ['sometimes', 'integer', 'between:1,120'],
        ]);
        $previa = DB::table('clima_reglas')->where('codigo', $codigo)->where('establecimiento_id', $establecimiento->id)->first();
        $parametros = array_replace($previa ? json_decode($previa->parametros, true) : [], $datos['parametros'] ?? []);
        DB::table('clima_reglas')->updateOrInsert(['codigo' => $codigo, 'establecimiento_id' => $establecimiento->id], [
            'tipo' => $global->tipo, 'nombre' => $global->nombre, 'recomendacion' => $global->recomendacion,
            'severidad' => $datos['severidad'] ?? $previa->severidad ?? $global->severidad,
            'activa' => $datos['activa'] ?? $previa->activa ?? true,
            'parametros' => json_encode((object) $parametros), 'updated_at' => now(), 'created_at' => $previa->created_at ?? now(),
        ]);
        $actualizar->handle($establecimiento);

        return response()->json(['data' => collect(ActualizarClima::reglasEfectivas($establecimiento->id, true))->firstWhere('codigo', $codigo)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> ClimaAlerta */
    public static function alerta(object $a): array
    {
        return ['id' => $a->id, 'regla' => $a->regla_codigo, 'tipo' => $a->tipo, 'severidad' => $a->severidad, 'titulo' => $a->titulo, 'detalle' => $a->detalle,
            'recomendacion' => $a->recomendacion, 'fuente' => $a->fuente, 'fuente_url' => $a->fuente_url, 'zona' => $a->zona,
            'valor' => Formato::decimal($a->valor), 'umbral' => Formato::decimal($a->umbral), 'unidad' => $a->unidad,
            'desde' => $a->desde, 'hasta' => $a->hasta, 'horizonte_dias' => (int) $a->horizonte_dias,
            'emitida_en' => Formato::fechaHora(Carbon::parse($a->emitida_en)), 'calculada_en' => Formato::fechaHora(Carbon::parse($a->calculada_en))];
    }
}
