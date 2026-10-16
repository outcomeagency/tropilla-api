<?php

namespace App\Domain\Hacienda\Proyecciones;

use App\Domain\Catalogos\Parametros;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recategorización sugerida (modelo-datos.md §6.4): recorre `reglas_categoria` de la especie por prioridad
 * descendente (las del establecimiento reemplazan a las globales de igual prioridad) y devuelve la primera
 * que cumple. Si coincide con la actual no hay sugerencia. Sin fecha de nacimiento, las reglas de edad no cumplen.
 */
final class SugeridorCategoria
{
    /** @var array<string, list<object>> */
    private array $reglas = [];

    /**
     * @param  array<string, mixed>  $s  fila de animales_estado (en construcción)
     * @return array{0: ?string, 1: ?int} [categoría sugerida, id de la regla]
     */
    public function evaluar(array $s, string $establecimientoId, ?Carbon $hoy = null): array
    {
        if (($s['estado'] ?? 'activo') !== 'activo') {
            return [null, null];
        }
        $hoy ??= now();
        $hechos = [
            'sexo' => $s['sexo'],
            'castrado' => $s['castrado'],
            'edad_meses' => $s['fecha_nacimiento'] ? (int) floor(Carbon::parse($s['fecha_nacimiento'])->diffInMonths($hoy, true)) : null,
            'destetado' => (bool) $s['destetado'],
            'partos' => (int) $s['partos'],
            'prenada' => $s['estado_reproductivo'] === 'prenada',
            'denticion' => $s['denticion'],
            'categoria' => $s['categoria_codigo'],
            'peso_kg' => $s['peso_kg'] === null ? null : (float) $s['peso_kg'],
            'estado_reproductivo' => $s['estado_reproductivo'],
        ];
        $param = fn (string $clave) => Parametros::valor($clave, $s['especie_codigo'], $s['raza_codigo'] ?? null, $establecimientoId);

        foreach ($this->reglas($s['especie_codigo'], $establecimientoId) as $r) {
            if (CondicionEvaluator::evaluar(json_decode($r->condiciones, true), $hechos, $param)) {
                return $r->categoria_codigo === $s['categoria_codigo'] ? [null, null] : [$r->categoria_codigo, (int) $r->id];
            }
        }

        return [null, null];
    }

    /** @return list<object> */
    private function reglas(string $especie, string $establecimientoId): array
    {
        return $this->reglas["{$especie}|{$establecimientoId}"] ??= (function () use ($especie, $establecimientoId) {
            $todas = DB::table('reglas_categoria')->where('especie_codigo', $especie)->where('activa', true)
                ->where(fn ($q) => $q->whereNull('establecimiento_id')->orWhere('establecimiento_id', $establecimientoId))
                ->orderByDesc('prioridad')->orderByRaw('establecimiento_id IS NULL')->get();
            $propias = $todas->whereNotNull('establecimiento_id')->pluck('prioridad')->all();

            return $todas->reject(fn ($r) => $r->establecimiento_id === null && in_array($r->prioridad, $propias, true))->values()->all();
        })();
    }
}
