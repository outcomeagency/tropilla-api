<?php

namespace App\Domain\Hacienda\Eventos;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Models\Evento;
use App\Models\User;
use stdClass;

/**
 * Corrección y anulación por la web (TPA-26): versión nueva del sobre con `corrige_evento_id`,
 * `hash_anterior` = huella de la versión corregida y `motivo` obligatorio. Nada se borra: la versión
 * anterior queda en el historial («ver cambios») y las proyecciones se recalculan.
 */
final class CorregirEvento
{
    public function __construct(private readonly RegistrarEvento $registrar) {}

    public function handle(Establecimiento $est, User $autor, Evento $base, stdClass $in, bool $anulacion): ResultadoRegistro
    {
        if (isset($in->id) && ($existente = Evento::query()->where('id', strtolower((string) $in->id))->where('establecimiento_id', $est->id)->first())) {
            return new ResultadoRegistro('duplicado', $existente, 0, '');
        }
        $vigente = Evento::query()->where('evento_raiz_id', $base->evento_raiz_id)->where('en_revision', false)->orderByDesc('version')->first();
        if ($vigente->id !== $base->id) {
            throw new EventoRechazado('version_no_vigente', 'Ese evento ya fue corregido: corregí la versión vigente.', ['vigente_id' => [$vigente->id]]);
        }
        if ($base->accion === 'anulacion') {
            throw new EventoRechazado('regla_bloqueante', 'El evento está anulado.');
        }

        return $this->registrar->handle($est, $autor, ArmarSobreWeb::nuevaVersion($est, $autor, $base, $in, $anulacion), [
            'canal' => 'web', 'confirmar_advertencias' => (bool) ($in->confirmar_advertencias ?? false),
        ]);
    }
}
