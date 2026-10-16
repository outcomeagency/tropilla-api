<?php

namespace App\Domain\Hacienda;

use App\Domain\Hacienda\Eventos\ConsultasEventos;
use App\Support\Formato;
use Illuminate\Support\Carbon;

/**
 * Serie de pesos de un animal (bugfix TPA-23) para la curva de la ficha, leída del payload de los eventos vigentes
 * (nunca del `resumen`). Fuentes: pesajes (individuales o promedio que lo incluyen, y los promedio del lote anónimo de
 * origen anteriores a la individualización = `heredado_de_lote`), peso al nacer (cría del parto) y peso al destete.
 * La GDP de cada punto individual se calcula como en el proyector (pesajes individuales + peso al nacer, contra el
 * punto anterior de fecha estrictamente menor): el último punto coincide con `gdp_kg_dia` de la ficha.
 */
final class SeriePesos
{
    private const TIPOS = ['hacienda.pesaje', 'reproduccion.parto', 'reproduccion.destete'];

    /**
     * @param  array{desde?: ?string, hasta?: ?string, incluir_lote?: bool}  $f
     * @return list<array<string, mixed>>
     */
    public static function de(object $animal, string $zonaHoraria, array $f = []): array
    {
        $eventos = ConsultasEventos::timelineAnimal($animal, [])
            ->whereIn('v.tipo', self::TIPOS)
            ->reorder('v.ocurrido_en')->orderBy('v.id')
            ->get();

        $puntos = [];
        $ultimo = null;   // último punto que cuenta para la GDP: [fecha, kg]
        $anterior = null; // el previo de fecha menor
        foreach ($eventos as $e) {
            $heredado = (bool) $e->heredado_de_lote;
            $p = json_decode($e->payload, true);
            $fecha = Carbon::parse($e->ocurrido_en)->setTimezone($zonaHoraria)->toDateString();
            $punto = match ($e->tipo) {
                'hacienda.pesaje' => ['fuente' => 'pesaje', 'modo' => $heredado ? 'promedio' : $p['modo'], 'peso_kg' => $p['peso_kg'],
                    'metodo' => $p['metodo'], 'cantidad' => $p['cantidad'] ?? null],
                'reproduccion.parto' => self::alNacer($animal->id, $p),
                'reproduccion.destete' => ! $heredado && isset($p['peso_kg'])
                    ? ['fuente' => 'destete', 'modo' => 'individual', 'peso_kg' => $p['peso_kg'], 'metodo' => null, 'cantidad' => null] : null,
            };
            if ($punto === null) {
                continue;
            }
            $gdp = null;
            $cuenta = $punto['modo'] === 'individual' && $punto['fuente'] !== 'destete';
            if ($cuenta) {
                // Igual que ProyectorAnimal: el «anterior» solo avanza si el último peso es de una fecha menor.
                if ($ultimo !== null && $ultimo[0] < $fecha) {
                    $anterior = $ultimo;
                }
                $ultimo = [$fecha, $punto['peso_kg']];
                if ($anterior !== null && ($dias = Carbon::parse($anterior[0])->diffInDays(Carbon::parse($fecha), true)) > 0) {
                    $gdp = round(((float) $punto['peso_kg'] - (float) $anterior[1]) / $dias, 3);
                }
            }
            if ((! ($f['incluir_lote'] ?? true) && $heredado) || (($f['desde'] ?? null) && $fecha < $f['desde']) || (($f['hasta'] ?? null) && $fecha > $f['hasta'])) {
                continue; // se filtra después de calcular: la GDP del primer punto visible usa el pesaje anterior
            }
            $puntos[] = [
                'evento_id' => $e->id, 'fecha' => $fecha, 'peso_kg' => Formato::decimal($punto['peso_kg']),
                'fuente' => $punto['fuente'], 'modo' => $punto['modo'], 'metodo' => $punto['metodo'], 'cantidad' => $punto['cantidad'],
                'gdp_kg_dia' => Formato::decimal($gdp), 'heredado_de_lote' => $heredado, 'lote_id' => $e->lote_id,
            ];
        }

        return $puntos;
    }

    /** @param array<string, mixed> $p @return array<string, mixed>|null */
    private static function alNacer(string $animalId, array $p): ?array
    {
        foreach ($p['crias'] ?? [] as $c) {
            if (($c['animal']['animal_id'] ?? null) === $animalId && isset($c['peso_kg'])) {
                return ['fuente' => 'nacimiento', 'modo' => 'individual', 'peso_kg' => $c['peso_kg'], 'metodo' => 'estimado', 'cantidad' => null];
            }
        }

        return null; // la madre del parto, o cría sin peso
    }
}
