<?php

namespace App\Http\Controllers\Api\V1\Clima;

use App\Domain\Clima\Jobs\CalcularEscenariosClimaticos;
use App\Domain\Establecimientos\Models\Establecimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class EscenarioClimaticoController
{
    public function show(Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('establecimiento.ver', $establecimiento);
        $fila = DB::table('escenarios_climaticos')->where('establecimiento_id', $establecimiento->id)->orderByDesc('created_at')->first();
        if ($fila === null || now()->diffInHours($fila->created_at) >= 24) {
            $lock = Cache::lock('escenarios:cola:'.$establecimiento->id, 900);
            if ($lock->get()) {
                CalcularEscenariosClimaticos::dispatch($establecimiento->id)->onQueue('default');
            }
        }
        if ($fila === null) {
            return response()->json(['data' => null, 'meta' => ['estado' => 'calculando', 'aviso' => 'El análisis se prepara en segundo plano. Volvé a consultar en unos minutos.']], 202);
        }

        return response()->json(['data' => ['id' => $fila->id, 'zona' => $fila->zona, 'emitido_en' => $fila->emitido_en,
            'horizonte_meses' => [1, 6], 'escenarios' => json_decode($fila->escenarios, true), 'plan_respuesta' => json_decode($fila->plan_respuesta, true),
            'resumen' => $fila->resumen, 'proveedor_ia' => $fila->proveedor_ia, 'fuentes' => json_decode($fila->datos_fuente, true),
            'aviso' => 'Escenarios orientativos con incertidumbre. La persona productora decide; no son pronósticos garantizados ni recetas veterinarias.'],
            'meta' => ['estado' => now()->diffInHours($fila->created_at) >= 24 ? 'actualizando' : 'actualizado']]);
    }
}
