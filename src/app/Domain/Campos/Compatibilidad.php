<?php

namespace App\Domain\Campos;

use App\Casts\PgTextArray;
use App\Domain\Clima\UbicacionClima;
use App\Domain\Establecimientos\Models\Establecimiento;
use Illuminate\Support\Facades\DB;

/**
 * «Campos compatibles con mi rodeo» (TPA-74). Compara un aviso con el rodeo del establecimiento (existencias) o con lo que el
 * productor pide a mano. Puntaje 0–100: especie 40 (sin especie no hay compatibilidad) + capacidad 30 (parcial ≥ 50 % o sin
 * cabezas pedidas: 15)
 * + categorías 15 + temporada 15. La distancia es aproximada al centro del departamento del aviso y se redondea a 10 km:
 * el aviso nunca expone coordenadas.
 */
final class Compatibilidad
{
    /** Rodeo actual por especie: total y cabezas por categoría. @return array<string, array{cabezas: int, categorias: array<string, int>}> */
    public static function rodeo(Establecimiento $est): array
    {
        $rodeo = [];
        foreach (DB::table('existencias')->where('establecimiento_id', $est->id)->where('cabezas', '>', 0)->get(['especie_codigo', 'categoria_codigo', 'cabezas']) as $e) {
            $rodeo[$e->especie_codigo]['cabezas'] = ($rodeo[$e->especie_codigo]['cabezas'] ?? 0) + (int) $e->cabezas;
            $rodeo[$e->especie_codigo]['categorias'][$e->categoria_codigo] = (int) $e->cabezas;
        }

        return $rodeo;
    }

    /** Punto de referencia del productor (establecimiento → centroide del departamento → capital), en WKT para PostGIS. */
    public static function origen(Establecimiento $est): ?string
    {
        $p = UbicacionClima::resolver($est);

        return $p ? sprintf('POINT(%F %F)', $p['lon'], $p['lat']) : null;
    }

    /** Distancias redondeadas a 10 km desde el origen a cada departamento. @param list<string> $deptos @return array<string, int> */
    public static function distancias(?string $origen, array $deptos): array
    {
        if ($origen === null || $deptos === []) {
            return [];
        }

        return DB::table('geo_departamentos')->whereIn('codigo', array_values(array_unique($deptos)))->whereNotNull('centroide')
            ->get(['codigo', DB::raw('ST_Distance(centroide, ST_GeogFromText('.DB::getPdo()->quote('SRID=4326;'.$origen).')) / 1000 AS km')])
            ->mapWithKeys(fn ($d) => [$d->codigo => (int) (max(10, round((float) $d->km / 10) * 10))])->all();
    }

    /**
     * @param  array<string, array{cabezas: int, categorias: array<string, int>}>  $rodeo
     * @param  array{especie?: ?string, cabezas?: ?int, categorias?: list<string>, desde?: ?string, hasta?: ?string}  $pedido
     * @return array<string, mixed>|null null si no hay con qué comparar
     */
    public static function evaluar(object $aviso, array $rodeo, array $pedido, ?int $distanciaKm): ?array
    {
        $especiesAviso = PgTextArray::parse($aviso->especies);
        $candidatas = ! empty($pedido['especie']) ? [$pedido['especie']] : array_keys($rodeo);
        if ($candidatas === []) {
            return null;
        }
        $comunes = array_values(array_intersect($candidatas, $especiesAviso));
        $especie = $comunes[0] ?? null;
        // Rara vez se mueve el rodeo entero: la capacidad se juzga solo contra las cabezas que el productor dice querer mover.
        $requeridas = ! empty($pedido['cabezas']) ? (int) $pedido['cabezas'] : 0;
        $capacidad = (int) $aviso->capacidad_cabezas;
        $capacidadEstado = $requeridas === 0 ? 'sin_dato' : ($capacidad >= $requeridas ? 'suficiente' : ($capacidad * 2 >= $requeridas ? 'parcial' : 'insuficiente'));

        $catsAviso = PgTextArray::parse($aviso->categorias);
        $catsMias = ! empty($pedido['categorias']) ? $pedido['categorias'] : ($especie ? array_keys($rodeo[$especie]['categorias'] ?? []) : []);
        $categoriasOk = $catsAviso === [] || $catsMias === [] || array_intersect($catsAviso, $catsMias) !== [];

        $hoy = now()->toDateString();
        $desde = $pedido['desde'] ?? null;
        $hasta = $pedido['hasta'] ?? null;
        $temporadaOk = $desde
            ? ($aviso->temporada_hasta === null || $aviso->temporada_hasta >= $desde) && ($hasta === null || $aviso->temporada_desde <= $hasta)
            : ($aviso->temporada_hasta === null || $aviso->temporada_hasta >= $hoy);

        $puntaje = $especie === null ? 0 : (40
            + ['suficiente' => 30, 'parcial' => 15, 'insuficiente' => 0, 'sin_dato' => 15][$capacidadEstado]
            + ($categoriasOk ? 15 : 0) + ($temporadaOk ? 15 : 0));

        $motivos = [];
        $motivos[] = $especie ? 'Recibe la especie de tu rodeo.' : 'No recibe la especie que buscás.';
        $motivos[] = match ($capacidadEstado) {
            'suficiente' => "Capacidad declarada para {$capacidad} cabezas: alcanza para las {$requeridas} tuyas.",
            'parcial' => "Capacidad declarada para {$capacidad} cabezas: alcanza para una parte de las {$requeridas}.",
            'insuficiente' => "Capacidad declarada para {$capacidad} cabezas: no alcanza para las {$requeridas}.",
            default => "Capacidad declarada para {$capacidad} cabezas. Indicá cuántas querés mover para compararla.",
        };
        if (! $categoriasOk) {
            $motivos[] = 'Pide categorías distintas a las de tu rodeo.';
        }
        $motivos[] = $temporadaOk ? 'La temporada coincide con tus fechas.' : 'La temporada no coincide con tus fechas.';

        return [
            'puntaje' => $puntaje, 'compatible' => $especie !== null && $capacidadEstado !== 'insuficiente' && $temporadaOk,
            'especie' => $especie, 'cabezas_requeridas' => $requeridas, 'capacidad' => $capacidadEstado,
            'categorias' => $categoriasOk, 'temporada' => $temporadaOk, 'distancia_km' => $distanciaKm, 'motivos' => $motivos,
        ];
    }
}
