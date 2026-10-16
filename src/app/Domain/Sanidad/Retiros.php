<?php

namespace App\Domain\Sanidad;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retiro vigente a nivel **lote anónimo** (TPA-35): los individuales lo tienen proyectado en `animales_estado`; un lote por
 * cantidades no tiene filas por animal, así que se deriva al leer de sus aplicaciones vigentes (`sujeto_tipo = lote`): fecha
 * local del evento (+ duración − 1 en tratamientos) + días de retiro del payload (snapshot del producto).
 */
final class Retiros
{
    /**
     * @param  ?string  $hasta  instante (UTC) tope: solo aplicaciones ocurridas hasta ahí (herencia al individualizar)
     * @return array{carne: ?string, leche: ?string} fecha hasta (inclusive) de cada retiro, o null
     */
    public static function deLote(string $loteId, ?string $hasta = null): array
    {
        $tz = (string) DB::table('lotes as l')->join('establecimientos as e', 'e.id', '=', 'l.establecimiento_id')->where('l.id', $loteId)->value('e.zona_horaria');
        $out = ['carne' => null, 'leche' => null];
        // Aplicaciones al lote y, desde el bugfix, las de un potrero donde estaba (resueltas al registrar en `evento_lotes`).
        $eventos = DB::table('eventos_vigentes as v')->where('v.accion', '<>', 'anulacion')->whereIn('v.tipo', ReglasSanitarias::APLICACIONES)
            ->where(fn ($q) => $q->where(fn ($q2) => $q2->where('v.lote_id', $loteId)->where('v.sujeto_tipo', 'lote'))
                ->orWhereExists(fn ($q2) => $q2->select(DB::raw(1))->from('evento_lotes as el')->whereColumn('el.evento_id', 'v.id')->where('el.lote_id', $loteId)))
            ->when($hasta, fn ($q, $h) => $q->where('v.ocurrido_en', '<=', $h))
            ->get(['v.tipo', 'v.ocurrido_en', 'v.payload']);
        foreach ($eventos as $e) {
            $p = json_decode($e->payload, true);
            $desde = Carbon::parse(Carbon::parse($e->ocurrido_en)->setTimezone($tz ?: 'UTC')->toDateString());
            if ($e->tipo === 'sanidad.tratamiento' && isset($p['duracion_dias'])) {
                $desde->addDays((int) $p['duracion_dias'] - 1);
            }
            foreach (['carne', 'leche'] as $t) {
                if (isset($p["retiro_{$t}_dias"])) {
                    $hasta = $desde->copy()->addDays((int) $p["retiro_{$t}_dias"])->toDateString();
                    $out[$t] = max($out[$t] ?? '0000-00-00', $hasta);
                }
            }
        }

        return $out;
    }

    public static function loteVigente(string $loteId, string $hoy): bool
    {
        $r = self::deLote($loteId);

        return ($r['carne'] !== null && $r['carne'] >= $hoy) || ($r['leche'] !== null && $r['leche'] >= $hoy);
    }
}
