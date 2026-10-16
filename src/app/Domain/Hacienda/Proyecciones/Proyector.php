<?php

namespace App\Domain\Hacienda\Proyecciones;

use App\Domain\Comercial\Services\Compromisos;
use App\Domain\Hacienda\Models\Evento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sync\Actions\RegistrarCambio;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta las proyecciones en la **misma transacción** que el insert del evento (modelo-datos §6.5):
 * animales afectados → lotes (antes y después) → existencias por especie → filas de `cambios` para el pull.
 */
final class Proyector
{
    public function __construct(
        private readonly ProyectorAnimal $animales,
        private readonly ProyectorLote $lotes,
        private readonly Existencias $existencias,
        private readonly RegistrarCambio $registrarCambio,
    ) {}

    /**
     * @param  list<string>  $animalIds
     * @param  list<string>  $loteIds
     */
    public function aplicarEvento(Evento $evento, array $animalIds, array $loteIds, Cadena $cadena): void
    {
        $est = $evento->establecimiento_id;
        $lotesAntes = $animalIds === [] ? [] : DB::table('animales_estado')->whereIn('animal_id', $animalIds)->whereNotNull('lote_id')->pluck('lote_id')->all();

        foreach ($animalIds as $id) {
            $this->animales->recalcular($id);
        }
        $lotesDespues = $animalIds === [] ? [] : DB::table('animales_estado')->whereIn('animal_id', $animalIds)->whereNotNull('lote_id')->pluck('lote_id')->all();
        $lotes = array_values(array_unique([...$loteIds, ...$lotesAntes, ...$lotesDespues]));
        foreach ($lotes as $id) {
            $this->lotes->recalcular($id);
        }

        $especies = array_unique([
            ...DB::table('animales')->whereIn('id', $animalIds)->pluck('especie_codigo')->all(),
            ...DB::table('lotes')->whereIn('id', $lotes)->pluck('especie_codigo')->all(),
        ]);
        foreach ($especies as $especie) {
            $this->existencias->recalcular($est, $especie);
        }

        foreach ($animalIds as $id) {
            $this->registrarCambio->handle($est, 'animal', $id, cadena: $cadena);
        }
        // Compromisos comerciales: una oferta sin hacienda activa pasa a vendida.
        Compromisos::liberarPorBaja($animalIds);
        foreach ($lotes as $id) {
            $this->registrarCambio->handle($est, 'lote', $id, cadena: $cadena);
        }
    }

    /** Reconstrucción total de un establecimiento (comando `proyecciones:reconstruir`). */
    public function reconstruir(string $establecimientoId): void
    {
        DB::transaction(function () use ($establecimientoId) {
            Cadena::bloquearDeEstablecimiento($establecimientoId);
            $animales = DB::table('animales')->where('establecimiento_id', $establecimientoId)->pluck('id')->all();
            DB::table('animal_identificaciones')->where('establecimiento_id', $establecimientoId)->delete();
            DB::table('animales_estado')->where('establecimiento_id', $establecimientoId)->delete();
            DB::table('lote_conteos')->where('establecimiento_id', $establecimientoId)->delete();
            DB::table('lotes_estado')->where('establecimiento_id', $establecimientoId)->delete();
            DB::table('existencias')->where('establecimiento_id', $establecimientoId)->delete();

            foreach ($animales as $id) {
                $this->animales->recalcular($id);
            }
            foreach (DB::table('lotes')->where('establecimiento_id', $establecimientoId)->pluck('id') as $id) {
                $this->lotes->recalcular($id);
            }
            foreach (DB::table('especies')->pluck('codigo') as $especie) {
                $this->existencias->recalcular($establecimientoId, $especie);
            }
        });
    }
}
