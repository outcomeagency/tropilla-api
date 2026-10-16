<?php

namespace App\Domain\Hacienda\Proyecciones;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recalcula `lote_conteos` y `lotes_estado` desde los eventos vigentes del lote (modelo-datos.md §6.2):
 * conteo por categoría (alta/ingreso/nacimiento +, egreso/muerte/pérdida/faena/individualización −,
 * recategorización = movimientos), potrero (último traslado del lote) y peso promedio (pesajes promedio).
 * Sin CHECK ≥ 0: si queda negativo se abre una revisión `conteo_negativo` (M13).
 */
final class ProyectorLote
{
    private const SUMAN = ['hacienda.alta', 'hacienda.ingreso', 'hacienda.nacimiento'];

    private const RESTAN = ['hacienda.egreso', 'hacienda.muerte', 'hacienda.perdida', 'hacienda.faena', 'hacienda.individualizacion'];

    public function recalcular(string $loteId): void
    {
        $lote = DB::table('lotes')->where('id', $loteId)->first();
        if ($lote === null) {
            return;
        }
        $tz = (string) DB::table('establecimientos')->where('id', $lote->establecimiento_id)->value('zona_horaria');

        $eventos = DB::table('eventos_vigentes')->where('lote_id', $loteId)->where('accion', '<>', 'anulacion')
            ->orderBy('ocurrido_en')->orderBy('creado_en')->orderBy('id')->get();

        $conteo = [];
        $potrero = null;
        $pesos = [];
        foreach ($eventos as $e) {
            $c = $e->conteo ? json_decode($e->conteo, true) : [];
            $p = json_decode($e->payload, true);
            if ($e->sujeto_tipo === 'lote') {
                if (in_array($e->tipo, self::SUMAN, true)) {
                    foreach ($c as $cat => $n) {
                        $conteo[$cat] = ($conteo[$cat] ?? 0) + $n;
                    }
                } elseif (in_array($e->tipo, self::RESTAN, true)) {
                    foreach ($c as $cat => $n) {
                        $conteo[$cat] = ($conteo[$cat] ?? 0) - $n;
                    }
                } elseif ($e->tipo === 'hacienda.recategorizacion') {
                    foreach ($p['movimientos'] ?? [] as $m) {
                        $conteo[$m['de']] = ($conteo[$m['de']] ?? 0) - $m['cantidad'];
                        $conteo[$m['a']] = ($conteo[$m['a']] ?? 0) + $m['cantidad'];
                    }
                }
            }
            if ($e->tipo === 'hacienda.traslado') {
                $potrero = $p['potrero_destino_id'];
            }
            if ($e->tipo === 'hacienda.pesaje' && $p['modo'] === 'promedio') {
                $pesos[] = ['fecha' => Carbon::parse($e->ocurrido_en)->setTimezone($tz)->toDateString(), 'peso' => (float) $p['peso_kg'], 'metodo' => $p['metodo']];
            }
        }

        DB::table('lote_conteos')->where('lote_id', $loteId)->delete();
        foreach ($conteo as $cat => $n) {
            if ($n !== 0) {
                DB::table('lote_conteos')->insert(['lote_id' => $loteId, 'categoria_codigo' => $cat, 'establecimiento_id' => $lote->establecimiento_id, 'cabezas' => $n]);
            }
        }
        if (array_filter($conteo, fn ($n) => $n < 0) && ! DB::table('revisiones')->where('entidad_tipo', 'lote')->where('entidad_id', $loteId)->where('tipo', 'conteo_negativo')->where('estado', 'abierta')->exists()) {
            DB::table('revisiones')->insert([
                'id' => (string) Str::uuid7(), 'establecimiento_id' => $lote->establecimiento_id, 'tipo' => 'conteo_negativo',
                'entidad_tipo' => 'lote', 'entidad_id' => $loteId, 'detalle' => json_encode(['conteo' => $conteo]), 'estado' => 'abierta',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $ultimo = end($pesos) ?: null;
        $previo = count($pesos) > 1 ? $pesos[count($pesos) - 2] : null;
        $gdp = null;
        if ($ultimo && $previo) {
            $dias = Carbon::parse($previo['fecha'])->diffInDays(Carbon::parse($ultimo['fecha']), true);
            $gdp = $dias > 0 ? round(($ultimo['peso'] - $previo['peso']) / $dias, 3) : null;
        }

        // Lote con animales individuales (modelo-datos §6.3): GDP = promedio de las GDP individuales; el peso promedio
        // sale de los pesajes individuales si son más recientes que el último pesaje promedio del lote.
        $ind = DB::table('animales_estado')->where('lote_id', $loteId)->where('estado', 'activo')->whereNotNull('peso_kg')
            ->selectRaw('avg(peso_kg) as peso, max(peso_fecha) as fecha, avg(gdp_kg_dia) as gdp, count(gdp_kg_dia) as con_gdp')->first();
        if ($ind && $ind->peso !== null && ($ultimo === null || $ind->fecha > $ultimo['fecha'])) {
            $ultimo = ['peso' => round((float) $ind->peso, 1), 'fecha' => $ind->fecha, 'metodo' => 'individual'];
        }
        if ($ind && (int) $ind->con_gdp > 0) {
            $gdp = round((float) $ind->gdp, 3);
        }

        DB::table('lotes_estado')->updateOrInsert(['lote_id' => $loteId], [
            'establecimiento_id' => $lote->establecimiento_id,
            'potrero_id' => $potrero,
            'cabezas_individuales' => DB::table('animales_estado')->where('lote_id', $loteId)->where('estado', 'activo')->count(),
            'cabezas_anonimas' => array_sum(array_filter($conteo, fn ($n) => $n > 0)),
            'peso_promedio_kg' => $ultimo['peso'] ?? null,
            'peso_promedio_fecha' => $ultimo['fecha'] ?? null,
            'peso_promedio_metodo' => $ultimo['metodo'] ?? null,
            'gdp_promedio_kg_dia' => $gdp,
            'actualizado_en' => now(),
        ]);
    }
}
