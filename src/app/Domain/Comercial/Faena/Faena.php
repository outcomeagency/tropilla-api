<?php

namespace App\Domain\Comercial\Faena;

use App\Support\Decimales as D;

/** Constantes y aritmética de kg del resultado de faena (TPA-67). Decimales exactos (bcmath), nunca floats. */
final class Faena
{
    public const ORIGENES = ['declarado', 'importado', 'transcripcion_revisada'];

    /** Res por debajo / por encima de esto (kg) se advierte como atípica (no se corrige). */
    public const RES_MIN_KG = '60';

    public const RES_MAX_KG = '450';

    public const LEYENDA_OFICIAL = 'Dato del frigorífico (romaneo / resultado de faena) tal como figura en el original.';

    public const LEYENDA_CALCULO = 'Cálculo propio de TROPILLA sobre la venta registrada y el resultado del frigorífico: orientativo, no reemplaza la liquidación.';

    /** Canónico a 1 decimal (como pesa la balanza del frigorífico). */
    public static function kg(string $v): string
    {
        return D::redondear($v, 1);
    }

    public static function sumar(string ...$v): string
    {
        return D::redondear(D::sum(...$v), 1);
    }

    public static function leyendaOrigen(string $origen): string
    {
        return match ($origen) {
            'importado' => __('Importado de la planilla digital del frigorífico'),
            'transcripcion_revisada' => __('Transcripción revisada del romaneo adjunto'),
            default => __('Cargado a mano por el productor'),
        };
    }
}
