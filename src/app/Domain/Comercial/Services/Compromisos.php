<?php

namespace App\Domain\Comercial\Services;

use Illuminate\Support\Facades\DB;

/**
 * Estado de compromiso comercial de animales y lotes (duda bloqueante nº 1 de Claude Design):
 * `libre` · `en_oferta` · `en_contrato` · `vendido`. Se **deriva** de las ofertas activas (publicada/reservada),
 * de los contratos a futuro abiertos (TPA-56, cuando existan) y del estado del animal. No se puede publicar ni
 * tokenizar lo que ya está comprometido (409 `comprometido`); lo que está en oferta sí puede venderse o pasar a
 * contrato retirando la oferta.
 */
final class Compromisos
{
    private const ACTIVAS = ['publicada', 'reservada'];

    /**
     * @param  list<string>  $animalIds
     * @return array<string, string> animal_id => en_oferta|en_contrato|vendido (los libres no aparecen)
     */
    public static function deAnimales(array $animalIds, ?string $excluirOferta = null): array
    {
        if ($animalIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('oferta_animales as oa')->join('ofertas as o', 'o.id', '=', 'oa.oferta_id')
            ->whereIn('oa.animal_id', $animalIds)->whereIn('o.estado', self::ACTIVAS)
            ->when($excluirOferta, fn ($q, $id) => $q->where('o.id', '<>', $id))->pluck('oa.animal_id') as $id) {
            $out[$id] = 'en_oferta';
        }
        if (DB::getSchemaBuilder()->hasTable('contrato_animales')) {
            foreach (DB::table('contrato_animales as ca')->join('contratos_futuro as c', 'c.id', '=', 'ca.contrato_id')
                ->whereIn('ca.animal_id', $animalIds)->whereNotIn('c.estado', ['borrador', 'cancelado', 'liquidado', 'incumplido'])->pluck('ca.animal_id') as $id) {
                $out[$id] = 'en_contrato';
            }
        }
        // Venta registrada con el trato (TPA-46): el animal queda vendido aunque todavía no salió del campo.
        if (DB::getSchemaBuilder()->hasTable('operacion_animales')) {
            foreach (DB::table('operacion_animales as oa')->join('operaciones as o', 'o.id', '=', 'oa.operacion_id')->whereIn('oa.animal_id', $animalIds)
                ->where('o.tipo', 'venta')->where('o.estado', 'registrada')->pluck('oa.animal_id') as $id) {
                $out[$id] = 'vendido';
            }
        }
        foreach (DB::table('animales_estado')->whereIn('animal_id', $animalIds)->where('estado', '<>', 'activo')->pluck('animal_id') as $id) {
            $out[$id] = 'vendido';
        }

        return $out;
    }

    public static function deAnimal(string $animalId): string
    {
        return self::deAnimales([$animalId])[$animalId] ?? 'libre';
    }

    /** Cabezas anónimas del lote ya comprometidas en otras ofertas activas. */
    public static function cabezasComprometidasDeLote(string $loteId, ?string $excluirOferta = null): int
    {
        return (int) DB::table('ofertas')->where('lote_id', $loteId)->whereIn('estado', self::ACTIVAS)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('oferta_animales as oa')->whereColumn('oa.oferta_id', 'ofertas.id'))
            ->when($excluirOferta, fn ($q, $id) => $q->where('id', '<>', $id))->sum('cantidad');
    }

    /**
     * Venta/baja de animales en oferta: la oferta pasa a `vendida` cuando ya no le queda ningún animal activo
     * (el compromiso se libera solo, sin dejar la oferta publicada con hacienda que ya no está).
     *
     * @param  list<string>  $animalIds
     */
    public static function liberarPorBaja(array $animalIds): void
    {
        if ($animalIds === [] || ! DB::getSchemaBuilder()->hasTable('oferta_animales')) {
            return;
        }
        $ofertas = DB::table('oferta_animales as oa')->join('ofertas as o', 'o.id', '=', 'oa.oferta_id')
            ->whereIn('oa.animal_id', $animalIds)->whereIn('o.estado', self::ACTIVAS)->distinct()->pluck('o.id');
        foreach ($ofertas as $id) {
            $quedan = DB::table('oferta_animales as oa')->join('animales_estado as a', 'a.animal_id', '=', 'oa.animal_id')
                ->where('oa.oferta_id', $id)->where('a.estado', 'activo')->exists();
            if (! $quedan) {
                DB::table('ofertas')->where('id', $id)->update(['estado' => 'vendida', 'motivo_retiro' => 'Hacienda dada de baja (egreso/venta).', 'retirada_en' => now(), 'updated_at' => now()]);
            }
        }
    }
}
