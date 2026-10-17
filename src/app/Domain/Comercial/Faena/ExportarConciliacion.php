<?php

namespace App\Domain\Comercial\Faena;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Http\Resources\V1\Comercial\ResultadoFaenaResource;
use App\Support\Gotenberg;

/**
 * Exportación de la conciliación (TPA-67): CSV (`;`, UTF-8 con BOM) con una fila por res y por animal faltante, marcando
 * qué columnas son del frigorífico y cuáles calcula TROPILLA; PDF (Gotenberg) para mandar al consignatario o al contador.
 * Se genera a pedido, no se guarda (precios privados).
 */
final class ExportarConciliacion
{
    public static function csv(object $r): string
    {
        $d = ResultadoFaenaResource::make($r, true);
        $porGarron = [];
        foreach ($d['conciliacion']['animales'] as $a) {
            if ($a['garron'] !== null) {
                $porGarron[$a['garron']] = $a;
            }
        }
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        $w = fn (array $fila) => fputcsv($out, $fila, ';', '"', '');
        $w(['frigorifico', 'fecha_faena', 'tropa', 'romaneo', 'dte', 'garron', 'identificacion', 'media_a_kg', 'media_b_kg', 'peso_res_kg', 'categoria',
            'tipificacion', 'decomiso', 'tropilla_animal_asociado', 'tropilla_estado', 'tropilla_peso_vivo_kg', 'tropilla_rinde_pct']);
        $o = $d['oficial'];
        foreach ($o['reses'] as $x) {
            $a = $porGarron[$x['garron']] ?? null;
            $w([$o['frigorifico'], $o['fecha_faena'], $o['tropa_numero'], $o['romaneo_numero'], $o['dte_numero'], $x['garron'], $x['identificacion'],
                $x['media_a_kg'], $x['media_b_kg'], $x['peso_kg'], $x['categoria'], $x['tipificacion'], $x['decomiso'],
                $a['identificacion'] ?? ($x['animal_id'] ? 'si' : 'no'), $a['estado'] ?? 'sin_asociar', $a['peso_vivo_kg'] ?? '', $a['rinde_pct'] ?? '']);
        }
        foreach ($d['conciliacion']['animales'] as $a) {
            if ($a['estado'] === 'faltante') {
                $w([$o['frigorifico'], $o['fecha_faena'], $o['tropa_numero'], $o['romaneo_numero'], $o['dte_numero'], '', '', '', '', '', '', '', '',
                    $a['identificacion'] ?? $a['animal_id'], 'faltante', $a['peso_vivo_kg'] ?? '', '']);
            }
        }
        $c = $d['conciliacion'];
        $w([]);
        $w(['resumen_tropilla', 'cabezas_enviadas', $c['cabezas']['enviadas'], 'reses', $c['cabezas']['reses'], 'kg_reses', $c['kg']['reses'],
            'kg_liquidados', $c['kg']['liquidados'], 'rinde_pct', $c['rinde_pct'], 'importe_esperado', $c['importe']['esperado_al_gancho'],
            'bruto_liquidado', $c['importe']['liquidado_bruto'], 'neto_liquidado', $c['importe']['liquidado_neto']]);
        rewind($out);

        return (string) stream_get_contents($out);
    }

    public static function pdf(Establecimiento $est, object $r): string
    {
        return Gotenberg::html(view('pdf.conciliacion-faena', ['d' => ResultadoFaenaResource::make($r, true), 'est' => $est])->render());
    }
}
