<?php

namespace App\Domain\Sanidad\Oficial;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Http\Resources\V1\Sanidad\SanidadOficialResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lectura consolidada de brucelosis/TBC del establecimiento (TPA-66): estatus vigente + historial, plan de saneamiento,
 * último protocolo, positivos (reactores de esa enfermedad) y vencimientos próximos. Base del tablero, la app y el
 * resumen para el veterinario.
 */
final class CondicionSanitaria
{
    /** @return array<string, mixed> */
    public static function resumen(Establecimiento $est, int $horizonteDias = 90): array
    {
        $hoy = Carbon::now($est->zona_horaria)->toDateString();
        $aviso = SanidadOficial::diasAviso($est->id);
        $enfermedades = [];
        foreach (SanidadOficial::ENFERMEDADES as $enf) {
            $vigente = DB::table('estatus_sanitarios')->where('establecimiento_id', $est->id)->where('enfermedad', $enf)->where('estado', 'vigente')->first();
            $historial = DB::table('estatus_sanitarios')->where('establecimiento_id', $est->id)->where('enfermedad', $enf)->where('estado', '<>', 'vigente')
                ->orderByDesc('vigente_desde')->orderByDesc('created_at')->limit(10)->get();
            $plan = DB::table('planes_saneamiento')->where('establecimiento_id', $est->id)->where('enfermedad', $enf)->where('estado', 'activo')->first();
            $ultimo = DB::table('protocolos_sanitarios')->where('establecimiento_id', $est->id)->where('enfermedad', $enf)->where('estado', 'vigente')
                ->orderByDesc('fecha_muestreo')->orderByDesc('created_at')->first();
            $enfermedades[] = [
                'enfermedad' => $enf,
                'estatus' => $vigente ? SanidadOficialResource::estatus($vigente, $hoy, $aviso) : null,
                'historial' => $historial->map(fn ($e) => SanidadOficialResource::estatus($e, $hoy, $aviso))->all(),
                'plan_saneamiento' => $plan ? SanidadOficialResource::plan($plan, $hoy) : null,
                'ultimo_protocolo' => $ultimo ? SanidadOficialResource::protocolo($ultimo, false, $hoy, $aviso) : null,
                'positivos' => self::positivos($est->id, $enf),
            ];
        }

        return [
            'enfermedades' => $enfermedades,
            'vencimientos' => self::vencimientos($est, $hoy, $horizonteDias, $aviso),
            'aviso' => __(SanidadOficial::AVISO),
            'calculado_al' => $hoy,
        ];
    }

    /** Reactores activos cuya prueba es de la enfermedad. @return list<array<string, mixed>> */
    public static function positivos(string $establecimientoId, string $enfermedad): array
    {
        return DB::table('animales_estado as a')->join('tipos_prueba as t', 't.codigo', '=', 'a.reactor_prueba')
            ->where('a.establecimiento_id', $establecimientoId)->where('a.estado', 'activo')->where('a.reactor', true)->where('t.enfermedad', $enfermedad)
            ->orderByDesc('a.reactor_desde')->orderBy('a.animal_id')
            ->get(['a.animal_id', 'a.identificacion_principal', 'a.especie_codigo', 'a.categoria_codigo', 'a.reactor_desde', 'a.reactor_prueba'])
            ->map(fn ($a) => ['animal_id' => $a->animal_id, 'identificacion' => $a->identificacion_principal, 'especie' => $a->especie_codigo,
                'categoria' => $a->categoria_codigo, 'desde' => $a->reactor_desde, 'prueba' => $a->reactor_prueba])->all();
    }

    /**
     * Vencimientos dentro del horizonte (y los ya vencidos que siguen vigentes): estatus, resultados para movimiento,
     * próximo muestreo y acciones del plan de saneamiento.
     *
     * @return list<array<string, mixed>>
     */
    public static function vencimientos(Establecimiento $est, string $hoy, int $horizonteDias, int $aviso): array
    {
        $hasta = Carbon::parse($hoy)->addDays($horizonteDias)->toDateString();
        $out = [];
        $agregar = function (string $tipo, string $enf, string $fecha, string $titulo, string $refId) use (&$out, $hoy, $aviso) {
            $out[] = ['tipo' => $tipo, 'enfermedad' => $enf, 'fecha' => $fecha, 'dias' => (int) Carbon::parse($hoy)->diffInDays(Carbon::parse($fecha), false),
                'vigencia' => SanidadOficial::vigencia($fecha, $hoy, $aviso), 'titulo' => $titulo, 'ref_id' => $refId];
        };
        foreach (DB::table('estatus_sanitarios')->where('establecimiento_id', $est->id)->where('estado', 'vigente')->whereNotNull('vence_el')->where('vence_el', '<=', $hasta)->get() as $e) {
            $agregar('estatus', $e->enfermedad, $e->vence_el, __('Vence el certificado de estatus (:n)', ['n' => $e->certificado_numero ?? __('sin número')]), $e->id);
        }
        // Resultados para movimiento: solo el último de cada enfermedad (uno nuevo reemplaza al anterior en la práctica).
        $mov = DB::table('protocolos_sanitarios')->where('establecimiento_id', $est->id)->where('estado', 'vigente')->where('motivo', 'movimiento')
            ->whereNotNull('vence_el')->where('vence_el', '>=', Carbon::parse($hoy)->subDays(30)->toDateString())->where('vence_el', '<=', $hasta)
            ->orderByDesc('fecha_muestreo')->get()->unique('enfermedad');
        foreach ($mov as $p) {
            $agregar('protocolo', $p->enfermedad, $p->vence_el, __('Vence el resultado para movimiento (protocolo :n)', ['n' => $p->protocolo_numero ?? __('s/n')]), $p->id);
        }
        foreach (DB::table('planes_saneamiento')->where('establecimiento_id', $est->id)->where('estado', 'activo')->get() as $p) {
            if ($p->proximo_muestreo !== null && $p->proximo_muestreo <= $hasta) {
                $agregar('muestreo', $p->enfermedad, $p->proximo_muestreo, __('Próximo muestreo del plan de saneamiento'), $p->id);
            }
            foreach (json_decode($p->acciones, true) as $a) {
                if ($a['hecha_el'] === null && $a['fecha_objetivo'] !== null && $a['fecha_objetivo'] <= $hasta) {
                    $agregar('accion', $p->enfermedad, $a['fecha_objetivo'], mb_substr($a['descripcion'], 0, 120), $p->id);
                }
            }
        }
        usort($out, fn ($a, $b) => [$a['fecha'], $a['tipo']] <=> [$b['fecha'], $b['tipo']]);

        return $out;
    }
}
