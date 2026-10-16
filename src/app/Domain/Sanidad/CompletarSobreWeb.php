<?php

namespace App\Domain\Sanidad;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\TiposEvento;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Completa el sobre que arma el servidor para la web (api-v1 A3: la web no hashea) **antes** de calcular la huella:
 * - Sanidad a un lote no anónimo → `sujetos.tipo = animales` con los miembros activos y `lote_id` de contexto (sync §3.1.1: lo mismo
 *   que hace la app al cargar). Así «aplicar a un lote» es un solo paso y cada ficha recibe el evento.
 * - Aplicación sanitaria con `producto_id`: copia al payload el snapshot del producto (nombre, principio activo, vía,
 *   retiros) si el usuario no los mandó. Cambiar el producto después no reescribe lo aplicado.
 * - Prueba diagnóstica con lecturas 0 h / 72 h y sin resultado: lo calcula con los umbrales vigentes (`ResultadoPrueba`).
 * Por push el sobre viene firmado por el teléfono: no se toca (la app hace lo mismo del lado del cliente).
 */
final class CompletarSobreWeb
{
    public static function completar(Establecimiento $est, stdClass $s): void
    {
        self::expandirLote($est, $s);
        if (($s->tipo ?? null) === 'sanidad.prueba_diagnostica' && ($s->payload ?? null) instanceof stdClass) {
            self::resultadoPrueba($est, $s);
        }
        if (in_array($s->tipo ?? null, ReglasSanitarias::APLICACIONES, true) && ($s->payload ?? null) instanceof stdClass && isset($s->payload->producto_id) && is_string($s->payload->producto_id)) {
            self::snapshotProducto($est, $s->tipo, $s->payload);
        }
    }

    private static function expandirLote(Establecimiento $est, stdClass $s): void
    {
        $suj = $s->sujetos ?? null;
        if (! $suj instanceof stdClass || ($suj->tipo ?? null) !== 'lote' || isset($suj->conteo) || ! is_string($suj->lote_id ?? null)) {
            return;
        }
        $tipo = TiposEvento::get((string) ($s->tipo ?? ''));
        // Solo sanidad: en hacienda un evento a lote tiene semántica propia (pesaje promedio, traslado del lote).
        if ($tipo === null || $tipo->modulo !== 'sanidad' || ! in_array('animales', $tipo->sujetos, true)) {
            return;
        }
        $lote = DB::table('lotes')->where('id', $suj->lote_id)->where('establecimiento_id', $est->id)->first();
        if ($lote === null || $lote->anonimo) {
            return;
        }
        $ids = DB::table('animales_estado')->where('lote_id', $lote->id)->where('estado', 'activo')->orderBy('animal_id')->pluck('animal_id')->all();
        if ($ids === []) {
            return;
        }
        $nuevo = new stdClass;
        $nuevo->tipo = 'animales';
        $nuevo->animal_ids = array_values($ids);
        $nuevo->lote_id = $lote->id;
        $s->sujetos = $nuevo;
    }

    /** Tuberculina cargada en la web con las dos lecturas y sin resultado → resultado calculado con los umbrales vigentes. */
    private static function resultadoPrueba(Establecimiento $est, stdClass $s): void
    {
        $p = $s->payload;
        $ids = $s->sujetos->animal_ids ?? null;
        if (isset($p->resultado) || ! isset($p->prueba, $p->lectura_0h_mm, $p->lectura_72h_mm) || ! is_array($ids) || count($ids) !== 1
            || ! is_string($p->lectura_0h_mm) || ! is_string($p->lectura_72h_mm) || ! is_numeric($p->lectura_0h_mm) || ! is_numeric($p->lectura_72h_mm)) {
            return;
        }
        $a = DB::table('animales_estado')->where('animal_id', $ids[0])->where('establecimiento_id', $est->id)->first(['especie_codigo', 'raza_codigo']);
        $calc = $a ? ResultadoPrueba::calcular((string) $p->prueba, $a->especie_codigo, $a->raza_codigo, $est->id, $p->lectura_0h_mm, $p->lectura_72h_mm) : null;
        if ($calc !== null) {
            $p->resultado = $calc['resultado'];
            $p->resultado_origen = 'calculado';
            $p->umbrales_version = $calc['umbrales_version'];
        }
    }

    private static function snapshotProducto(Establecimiento $est, string $tipo, stdClass $p): void
    {
        $prod = DB::table('productos_sanitarios')->where('id', strtolower($p->producto_id))->where('establecimiento_id', $est->id)->first();
        if ($prod === null) {
            return; // RegistrarEvento responde dependencia_faltante
        }
        $p->producto_nombre ??= $prod->nombre_comercial;
        if ($tipo === 'sanidad.desparasitacion' && $prod->principio_activo !== null) {
            $p->principio_activo ??= $prod->principio_activo;
        }
        if ($tipo === 'sanidad.vacunacion' && $prod->via !== null) {
            $p->via ??= $prod->via;
        }
        foreach (['retiro_carne_dias', 'retiro_leche_dias'] as $c) {
            if (! property_exists($p, $c) && $prod->{$c} !== null) {
                $p->{$c} = (int) $prod->{$c};
            }
        }
    }
}
