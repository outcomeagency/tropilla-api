<?php

namespace App\Domain\Comercial;

use App\Support\Decimales as D;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Historial de precios propio (TPA-47): ventas registradas por especie y categoría contra una referencia **declarada**.
 * Referencia de cada punto: la que se eligió en la operación; si no, la última declarada a esa fecha con la **misma modalidad**
 * (precios en pie y al gancho no se comparan) — o, con `?referencia=<descripción>`, esa serie aunque sea de otra especie
 * (p. ej. el búfalo contra el «novillo overo», duda 10). diferencial = (precio − referencia) / referencia × 100.
 */
final class HistorialPrecios
{
    /**
     * @param  array{especie?: string, categoria?: string, desde?: string, hasta?: string, referencia?: string}  $f
     * @return list<array<string, mixed>>
     */
    public static function de(string $establecimientoId, array $f): array
    {
        $ops = DB::table('operaciones')->where('establecimiento_id', $establecimientoId)->where('tipo', 'venta')->where('estado', 'registrada')
            ->when($f['especie'] ?? null, fn ($q, $v) => $q->where('especie_codigo', $v))
            ->when($f['categoria'] ?? null, fn ($q, $v) => $q->where('categoria_codigo', $v))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('fecha', '>=', $v))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('fecha', '<=', $v))
            ->orderBy('especie_codigo')->orderBy('categoria_codigo')->orderBy('fecha')->orderBy('id')->get();

        $grupos = [];
        foreach ($ops as $o) {
            $ref = self::referencia($establecimientoId, $o, $f['referencia'] ?? null);
            $dif = $ref ? D::redondear(D::mul(D::div(D::sub((string) $o->precio_kg, (string) $ref->precio_kg), (string) $ref->precio_kg), '100'), 2) : null;
            $grupos[$o->especie_codigo.'|'.$o->categoria_codigo][] = [
                'fecha' => Carbon::parse($o->fecha)->toDateString(), 'precio_kg' => Formato::decimal($o->precio_kg), 'modalidad' => $o->modalidad,
                'referencia_kg' => $ref ? Formato::decimal($ref->precio_kg) : null, 'diferencial_pct' => $dif, 'operacion_id' => $o->id,
            ];
        }

        return array_values(array_map(function ($clave, $puntos) {
            [$especie, $categoria] = explode('|', $clave);
            $difs = array_values(array_filter(array_column($puntos, 'diferencial_pct'), fn ($d) => $d !== null));

            return ['especie' => $especie, 'categoria' => $categoria, 'puntos' => $puntos,
                'diferencial_promedio_pct' => $difs === [] ? null : D::redondear(D::div(D::sum(...$difs), (string) count($difs)), 2)];
        }, array_keys($grupos), $grupos));
    }

    private static function referencia(string $est, object $o, ?string $descripcion): ?object
    {
        if ($descripcion === null && $o->precio_referencia_id) {
            $r = DB::table('precios_referencia')->where('id', $o->precio_referencia_id)->first();

            return $r && $r->modalidad === $o->modalidad ? $r : null;
        }
        $q = DB::table('precios_referencia')->where('establecimiento_id', $est)->where('modalidad', $o->modalidad)->where('fecha', '<=', $o->fecha);
        if ($descripcion !== null) {
            $q->whereRaw('lower(descripcion) = lower(?)', [$descripcion]);
        } else {
            $q->where('especie_codigo', $o->especie_codigo)->where(fn ($w) => $w->where('categoria_codigo', $o->categoria_codigo)->orWhereNull('categoria_codigo'))
                ->orderByRaw('categoria_codigo IS NULL'); // la de la misma categoría primero
        }

        return $q->orderByDesc('fecha')->orderByDesc('created_at')->first();
    }
}
