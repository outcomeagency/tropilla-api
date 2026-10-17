<?php

namespace App\Domain\Sanidad\Oficial;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Http\Resources\V1\Sanidad\SanidadOficialResource;
use App\Support\Gotenberg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resumen para el veterinario (TPA-66): estatus, plan, positivos, protocolos de los últimos meses con su resultado por
 * animal y vencimientos. PDF (Gotenberg) para imprimir o mandar; CSV por animal para su planilla. Privado del establecimiento:
 * se genera a pedido y no se guarda. Lleva el aviso de que no reemplaza SIGSA ni certifica nada.
 */
final class ResumenVeterinario
{
    /** @return array<string, mixed> */
    public static function datos(Establecimiento $est, int $meses): array
    {
        $r = CondicionSanitaria::resumen($est, 120);
        $hoy = $r['calculado_al'];
        $desde = Carbon::parse($hoy)->subMonths($meses)->toDateString();
        foreach ($r['enfermedades'] as &$e) {
            $e['protocolos'] = DB::table('protocolos_sanitarios')->where('establecimiento_id', $est->id)->where('enfermedad', $e['enfermedad'])
                ->where('estado', 'vigente')->where('fecha_muestreo', '>=', $desde)->orderByDesc('fecha_muestreo')->limit(20)->get()
                ->map(fn ($p) => SanidadOficialResource::protocolo($p, true, $hoy))->all();
        }
        unset($e);

        $categorias = DB::table('categorias')->pluck('nombres', 'codigo')->map(fn ($n) => json_decode($n, true)['es'] ?? null)->all();

        return [...$r, 'categorias' => $categorias, 'establecimiento' => $est->nombre, 'renspa' => $est->renspa, 'fecha' => $hoy, 'horizonte_dias' => 120, 'meses' => $meses];
    }

    public static function pdf(Establecimiento $est, int $meses): string
    {
        return Gotenberg::html(view('pdf.resumen-veterinario', ['r' => self::datos($est, $meses)])->render());
    }

    /** Una fila por resultado de animal (separador `;`, UTF-8 con BOM para que Excel respete los acentos). */
    public static function csv(Establecimiento $est, int $meses): string
    {
        $r = self::datos($est, $meses);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['enfermedad', 'estatus', 'estatus_origen', 'estatus_vence', 'protocolo', 'laboratorio', 'prueba', 'motivo', 'fecha_muestreo',
            'fecha_resultado', 'veterinario', 'matricula', 'origen', 'orden', 'identificacion', 'asociado_a_tropilla', 'categoria', 'muestra',
            'lectura_0h_mm', 'lectura_72h_mm', 'resultado'], ';', '"', '');
        foreach ($r['enfermedades'] as $e) {
            $st = $e['estatus'];
            foreach ($e['protocolos'] as $p) {
                foreach ($p['resultados'] as $x) {
                    fputcsv($out, [$e['enfermedad'], $st['estatus'] ?? '', $st['origen'] ?? '', $st['vence_el'] ?? '', $p['protocolo_numero'] ?? '', $p['laboratorio'] ?? '',
                        $p['prueba'], $p['motivo'], $p['fecha_muestreo'], $p['fecha_resultado'] ?? '', $p['veterinario']['nombre'] ?? '', $p['veterinario']['matricula'] ?? '',
                        $p['origen'], $x['orden'], $x['identificacion'] ?? '', $x['animal_id'] ? 'si' : 'no', $x['categoria_codigo'] ?? ($x['animal']['categoria'] ?? ''),
                        $x['muestra'] ?? '', $x['lectura_0h_mm'] ?? '', $x['lectura_72h_mm'] ?? '', $x['resultado']], ';', '"', '');
                }
            }
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
