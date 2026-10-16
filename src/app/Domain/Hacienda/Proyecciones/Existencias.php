<?php

namespace App\Domain\Hacienda\Proyecciones;

use Illuminate\Support\Facades\DB;

/**
 * `existencias` por (establecimiento, especie, categoría) = animales activos + cabezas de lotes anónimos
 * (modelo-datos.md §6.2). Recuento agregado, barato; se rehace por (establecimiento, especie).
 */
final class Existencias
{
    public function recalcular(string $establecimientoId, string $especie): void
    {
        $ind = DB::table('animales_estado')->where('establecimiento_id', $establecimientoId)->where('especie_codigo', $especie)
            ->where('estado', 'activo')->groupBy('categoria_codigo')->selectRaw('categoria_codigo, count(*) as n')->pluck('n', 'categoria_codigo')->all();
        $anon = DB::table('lote_conteos as lc')->join('lotes as l', 'l.id', '=', 'lc.lote_id')
            ->where('l.establecimiento_id', $establecimientoId)->where('l.especie_codigo', $especie)
            ->groupBy('lc.categoria_codigo')->selectRaw('lc.categoria_codigo, sum(lc.cabezas) as n')->pluck('n', 'categoria_codigo')->all();

        DB::table('existencias')->where('establecimiento_id', $establecimientoId)->where('especie_codigo', $especie)->delete();
        foreach (array_unique([...array_keys($ind), ...array_keys($anon)]) as $cat) {
            $i = (int) ($ind[$cat] ?? 0);
            $a = max(0, (int) ($anon[$cat] ?? 0));
            if ($i + $a === 0) {
                continue;
            }
            DB::table('existencias')->insert([
                'establecimiento_id' => $establecimientoId, 'especie_codigo' => $especie, 'categoria_codigo' => $cat,
                'cabezas' => $i + $a, 'individuales' => $i, 'anonimas' => $a, 'actualizado_en' => now(),
            ]);
        }
    }
}
