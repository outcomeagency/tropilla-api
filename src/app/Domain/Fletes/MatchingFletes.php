<?php

namespace App\Domain\Fletes;

use App\Domain\Establecimientos\Data\Provincias;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sugerencias de camión para un pedido (TPA-73). Ordena disponibilidades abiertas con criterios explícitos y explicados:
 * ventana de fechas, especie, cercanía de la salida (centroides de departamento), destino, capacidad, viaje de retorno y
 * documentación verificada. Es una ayuda para el concierge: la propuesta la decide y la confirma una persona.
 */
final class MatchingFletes
{
    /**
     * @param  object  $pedido  fila de `fletes_pedidos`
     * @return list<array<string, mixed>>
     */
    public function sugerencias(object $pedido): array
    {
        $hoy = Carbon::today()->toDateString();
        $filas = DB::table('fletes_disponibilidades as d')
            ->join('fletes_transportistas as t', 't.id', '=', 'd.transportista_id')
            ->leftJoin('geo_departamentos as go', 'go.codigo', '=', 'd.origen_departamento_codigo')
            ->leftJoin('geo_departamentos as gp', fn ($j) => $j->where('gp.codigo', '=', $pedido->origen_departamento_codigo))
            ->where('d.estado', 'abierta')->where('t.activo', true)
            ->where('d.desde', '<=', $pedido->fecha_hasta)->where('d.hasta', '>=', $pedido->fecha_desde)->where('d.hasta', '>=', $hoy)
            ->whereRaw('? = ANY(d.especies)', [$pedido->especie_codigo])
            ->selectRaw('d.*, t.nombre as transportista_nombre, go.nombre as origen_nombre,
                CASE WHEN go.centroide IS NULL OR gp.centroide IS NULL THEN NULL ELSE ST_Distance(go.centroide, gp.centroide) / 1000 END as km')
            ->get();

        $radio = (int) config('tropilla.fletes.radio_km');
        $salida = [];
        foreach ($filas as $d) {
            $km = $d->km === null ? ($d->origen_departamento_codigo === $pedido->origen_departamento_codigo ? 0.0 : null) : (float) $d->km;
            $destinoOk = $d->destino_provincia_codigo === null || $d->destino_provincia_codigo === $pedido->destino_provincia_codigo;
            if ($km === null || $km > $radio || ! $destinoOk) {
                continue;
            }
            $salida[] = $this->evaluar($pedido, $d, $km, $radio);
        }
        usort($salida, fn ($a, $b) => [$b['proponible'], $b['puntaje']] <=> [$a['proponible'], $a['puntaje']]);

        return $salida;
    }

    /**
     * Compatibilidad congelada en la propuesta. Sin disponibilidad (propuesta manual del concierge) solo se evalúa la documentación.
     *
     * @return array<string, mixed>
     */
    public function compatibilidad(object $pedido, ?object $disponibilidad, string $transportistaId): array
    {
        if ($disponibilidad === null) {
            $doc = Fletes::documentacion($transportistaId);

            return ['puntaje' => null, 'proponible' => $doc['completa'], 'documentacion_completa' => $doc['completa'],
                'criterios' => [self::criterioDocumentacion($doc), ['criterio' => 'manual', 'cumple' => true,
                    'detalle' => 'Propuesta cargada por el equipo sin disponibilidad publicada: ruta, fecha y capacidad se confirmaron por contacto directo.']]];
        }
        $km = DB::selectOne('SELECT ST_Distance(a.centroide, b.centroide) / 1000 AS km FROM geo_departamentos a, geo_departamentos b WHERE a.codigo = ? AND b.codigo = ?',
            [$disponibilidad->origen_departamento_codigo, $pedido->origen_departamento_codigo])?->km;
        $km = $km === null ? ($disponibilidad->origen_departamento_codigo === $pedido->origen_departamento_codigo ? 0.0 : null) : (float) $km;

        return $this->evaluar($pedido, $disponibilidad, $km, (int) config('tropilla.fletes.radio_km'));
    }

    /** @return array<string, mixed> */
    private function evaluar(object $pedido, object $d, ?float $km, int $radio): array
    {
        $doc = Fletes::documentacion($d->transportista_id);
        $capacidadOk = (int) $d->capacidad_cabezas >= (int) $pedido->cabezas;
        $viajes = (int) ceil((int) $pedido->cabezas / max(1, (int) $d->capacidad_cabezas));
        $retorno = (bool) $d->es_retorno && $d->destino_provincia_codigo !== null;
        $desde = max($d->desde, $pedido->fecha_desde);
        $hasta = min($d->hasta, $pedido->fecha_hasta);

        $puntaje = 100;
        $puntaje -= $km === null ? 20 : (int) round(min(1, $km / max(1, $radio)) * 40);
        $puntaje -= $capacidadOk ? 0 : 25;
        $puntaje -= $doc['completa'] ? 0 : 30;
        $puntaje += $retorno ? 10 : 0;
        $puntaje += $d->destino_provincia_codigo !== null ? 5 : 0;
        $puntaje = max(0, min(100, $puntaje));

        $destino = $d->destino_provincia_codigo === null ? 'Acepta cualquier destino.'
            : 'Va hacia '.(Provincias::TODAS[$d->destino_provincia_codigo] ?? $d->destino_provincia_codigo).'.';

        return [
            'disponibilidad_id' => $d->id, 'transportista_id' => $d->transportista_id,
            'transportista_nombre' => $d->transportista_nombre ?? DB::table('fletes_transportistas')->where('id', $d->transportista_id)->value('nombre'),
            'jaula' => $d->jaula, 'capacidad_cabezas' => (int) $d->capacidad_cabezas, 'es_retorno' => (bool) $d->es_retorno,
            'tarifa_km' => $d->tarifa_km !== null ? (string) $d->tarifa_km : null,
            'fechas_en_comun' => ['desde' => $desde, 'hasta' => $hasta],
            'distancia_salida_km' => $km === null ? null : (int) round($km),
            'puntaje' => $puntaje, 'proponible' => $doc['completa'], 'documentacion_completa' => $doc['completa'],
            'criterios' => [
                ['criterio' => 'fechas', 'cumple' => true, 'detalle' => sprintf('Disponible del %s al %s, dentro de la ventana pedida.', Carbon::parse($desde)->format('d/m'), Carbon::parse($hasta)->format('d/m'))],
                ['criterio' => 'salida', 'cumple' => $km !== null, 'detalle' => $km === null ? 'Sin ubicación de referencia para medir la distancia.'
                    : sprintf('Sale a unos %d km de la zona de carga (entre cabeceras de departamento, en línea recta).', (int) round($km))],
                ['criterio' => 'destino', 'cumple' => true, 'detalle' => $destino],
                ['criterio' => 'capacidad', 'cumple' => $capacidadOk, 'detalle' => $capacidadOk
                    ? "Capacidad declarada: {$d->capacidad_cabezas} cabezas (pedido: {$pedido->cabezas})."
                    : "Capacidad declarada: {$d->capacidad_cabezas} cabezas; harían falta {$viajes} viajes o más de un camión."],
                ['criterio' => 'retorno', 'cumple' => $retorno, 'detalle' => $retorno ? 'Es un viaje de retorno: aprovecha kilómetros que haría vacío.' : 'No es viaje de retorno.'],
                self::criterioDocumentacion($doc),
            ],
        ];
    }

    /** @param array{completa: bool, faltantes: list<string>} $doc @return array<string, mixed> */
    private static function criterioDocumentacion(array $doc): array
    {
        return ['criterio' => 'documentacion', 'cumple' => $doc['completa'], 'detalle' => $doc['completa']
            ? 'Documentación verificada por el equipo y vigente.'
            : 'Falta verificar: '.implode('; ', array_map(fn ($i) => Fletes::DOCUMENTOS_TRANSPORTISTA[$i], $doc['faltantes'])).'.'];
    }
}
