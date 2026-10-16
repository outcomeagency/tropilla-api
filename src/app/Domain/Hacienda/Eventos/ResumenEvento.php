<?php

namespace App\Domain\Hacienda\Eventos;

use App\Domain\Catalogos\Catalogos;
use App\Support\Formato;
use Illuminate\Support\Facades\DB;

/** Texto corto para listas y timelines (`EventoResumen.resumen`). Sin precios ni datos privados. */
final class ResumenEvento
{
    /** @var array<string, string> */
    private static array $nombres = [];

    public static function tipoNombre(string $tipo): string
    {
        $locale = app()->getLocale();

        return self::$nombres["{$tipo}|{$locale}"] ??= (string) (Catalogos::nombre(DB::table('tipos_evento')->where('codigo', $tipo)->value('nombres'), $locale) ?? $tipo);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>|null  $conteo
     */
    public static function de(string $tipo, array $p, ?array $conteo, int $animales): string
    {
        $cabezas = $conteo ? array_sum($conteo) : $animales;
        $cab = $cabezas === 1 ? '1 animal' : "{$cabezas} cabezas";
        $kg = fn ($v) => str_replace('.', ',', (string) Formato::decimal((string) $v)).' kg';

        return match ($tipo) {
            'hacienda.pesaje' => ($p['modo'] === 'promedio' ? 'Promedio ' : '').$kg($p['peso_kg']).' ('.$p['metodo'].')'.($p['modo'] === 'promedio' ? ", {$p['cantidad']} cab." : ''),
            'hacienda.alta' => "Alta de {$cab}",
            'hacienda.ingreso' => "Ingreso de {$cab} ({$p['motivo']})",
            'hacienda.egreso' => "Egreso de {$cab} ({$p['motivo']}".(isset($p['destino']) ? ", {$p['destino']}" : '').')',
            'hacienda.nacimiento' => "Nacimiento: {$cab}",
            'hacienda.muerte' => "Muerte de {$cab} ({$p['causa']})",
            'hacienda.traslado' => "Traslado de {$cab}",
            'hacienda.cambio_lote' => "Cambio de lote de {$cab}",
            'hacienda.recategorizacion' => isset($p['categoria_nueva']) ? "Recategorizado a {$p['categoria_nueva']}" : 'Recategorización',
            'hacienda.identificacion' => ucfirst($p['accion']).": {$p['identificacion']['tipo']} {$p['identificacion']['valor']}",
            'hacienda.denticion' => "Dentición: {$p['denticion']}",
            'hacienda.condicion_corporal' => "CC {$p['valor']} (escala {$p['escala']})",
            'reproduccion.tacto' => (['prenada' => 'Preñada', 'vacia' => 'Vacía'][$p['resultado']] ?? $p['resultado'])
                .(isset($p['dias_gestacion']) ? " ({$p['dias_gestacion']} días)" : (isset($p['tamano']) ? " ({$p['tamano']})" : '')),
            'reproduccion.parto' => 'Parto '.$p['tipo'].': '.count($p['crias']).' cría(s)',
            'reproduccion.inseminacion' => strtoupper($p['tipo']),
            'reproduccion.servicio_natural' => "Servicio natural desde {$p['inicio']}",
            'reproduccion.destete' => "Destete {$p['tipo']}".(isset($p['peso_kg']) ? ', '.$kg($p['peso_kg']) : ''),
            'sanidad.vacunacion' => "{$p['producto_nombre']} ({$p['enfermedad']}) — {$cab}",
            'sanidad.desparasitacion' => "{$p['producto_nombre']} — {$cab}",
            'sanidad.tratamiento' => $p['diagnostico_presuntivo'],
            'sanidad.prueba_diagnostica' => "{$p['prueba']}: {$p['resultado']}",
            default => self::tipoNombre($tipo),
        };
    }
}
