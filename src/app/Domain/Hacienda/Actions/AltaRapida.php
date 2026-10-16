<?php

namespace App\Domain\Hacienda\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Eventos\ArmarSobreWeb;
use App\Domain\Hacienda\Eventos\RegistrarEvento;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

/**
 * Alta rápida (TPA-16): atajo que crea un evento `hacienda.alta` (motivo `alta_rapida`) con un solo animal.
 * Mismo camino que cualquier evento (sobre, huella, cadena, proyección). La app offline manda el sobre por push.
 */
final class AltaRapida
{
    public function __construct(private readonly RegistrarEvento $registrar) {}

    /** @return string id del animal */
    public function handle(Establecimiento $est, User $autor, stdClass $in): string
    {
        $animal = $in->animal;
        if (empty($animal->animal_id)) {
            $animal->animal_id = (string) Str::uuid7();
        }
        // Campos opcionales en null se omiten (el payload no admite null).
        foreach (get_object_vars($animal) as $k => $v) {
            if ($v === null) {
                unset($animal->{$k});
            }
        }

        $evento = new stdClass;
        if (! empty($in->id)) {
            $evento->id = $in->id;
        }
        $evento->tipo = 'hacienda.alta';
        $evento->sujetos = (object) ['tipo' => 'animales', 'animal_ids' => [$animal->animal_id]];
        if (! empty($animal->lote_id)) {
            $evento->sujetos->lote_id = $animal->lote_id;
        }
        $evento->payload = (object) ['motivo' => 'alta_rapida', 'animales' => [$animal]];
        $evento->ocurrido_en = $in->ocurrido_en ?? now()->setTimezone($est->zona_horaria)->toDateString();

        $this->registrar->handle($est, $autor, ArmarSobreWeb::alta($est, $autor, $evento), [
            'canal' => 'web', 'confirmar_advertencias' => (bool) ($in->confirmar_advertencias ?? false),
        ]);

        return $animal->animal_id;
    }
}
