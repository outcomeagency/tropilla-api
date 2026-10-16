<?php

namespace App\Domain\Catalogos;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolución de parámetros (modelo-datos.md §3.1): la fila vigente más específica en el orden
 * establecimiento+raza → establecimiento → raza → especie → global. `vigente_desde` no se pisa:
 * sobrescribir = fila nueva con otra fecha (queda el histórico).
 */
final class Parametros
{
    /** Valor del parámetro (o null si no existe). */
    public static function valor(string $clave, ?string $especie = null, ?string $raza = null, ?string $establecimientoId = null, ?Carbon $fecha = null): mixed
    {
        return self::resolver($clave, $especie, $raza, $establecimientoId, $fecha)['valor'] ?? null;
    }

    /**
     * @return array{clave: string, valor: mixed, unidad: ?string, especie: ?string, raza: ?string, origen: string, validado_por_vet: bool, fuente: string}|null
     */
    public static function resolver(string $clave, ?string $especie = null, ?string $raza = null, ?string $establecimientoId = null, ?Carbon $fecha = null): ?array
    {
        $fecha = ($fecha ?? now())->toDateString();
        $filas = DB::table('parametros')
            ->where('clave', $clave)
            ->where('vigente_desde', '<=', $fecha)
            ->where(fn ($q) => $q->whereNull('especie_codigo')->orWhere('especie_codigo', $especie))
            ->where(fn ($q) => $q->whereNull('raza_codigo')->orWhere('raza_codigo', $raza))
            ->where(fn ($q) => $q->whereNull('establecimiento_id')->orWhere('establecimiento_id', $establecimientoId))
            ->get();

        $mejor = $filas->sortByDesc(fn ($f) => [self::rango($f), $f->vigente_desde])->first();

        return $mejor === null ? null : self::formatear($mejor);
    }

    /**
     * Todos los parámetros resueltos para un establecimiento (y especie/raza opcionales).
     *
     * @return list<array<string, mixed>>
     */
    public static function resueltos(string $establecimientoId, ?string $especie = null, ?string $raza = null): array
    {
        $claves = DB::table('parametros')
            ->where(fn ($q) => $q->whereNull('establecimiento_id')->orWhere('establecimiento_id', $establecimientoId))
            ->distinct()->orderBy('clave')->pluck('clave');

        // Una fila por (clave, especie): la global (especie null) y la de cada especie pedida.
        $especies = $especie ? [null, $especie] : [null, ...DB::table('especies')->orderBy('orden')->pluck('codigo')->all()];
        $salida = [];
        foreach ($claves as $clave) {
            foreach ($especies as $e) {
                $r = self::resolver($clave, $e, $raza, $establecimientoId);
                if ($r !== null && $r['especie'] === $e) {
                    $salida[] = $r;
                }
            }
        }

        return $salida;
    }

    /** Especificidad: establecimiento (+8), raza (+4), especie (+2). */
    private static function rango(object $f): int
    {
        return ($f->establecimiento_id ? 8 : 0) + ($f->raza_codigo ? 4 : 0) + ($f->especie_codigo ? 2 : 0);
    }

    /** @return array<string, mixed> */
    private static function formatear(object $f): array
    {
        return [
            'clave' => $f->clave,
            'valor' => json_decode($f->valor, true),
            'unidad' => $f->unidad,
            'especie' => $f->especie_codigo,
            'raza' => $f->raza_codigo,
            'origen' => $f->establecimiento_id ? 'establecimiento' : ($f->raza_codigo ? 'raza' : ($f->especie_codigo ? 'especie' : 'global')),
            'validado_por_vet' => (bool) $f->validado_por_vet,
            'fuente' => $f->fuente,
        ];
    }
}
