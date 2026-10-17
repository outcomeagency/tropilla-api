<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Exceptions\ApiException;

/** Registro de familias extraíbles. Agregar una familia = una clase nueva acá (y en el CHECK de la migración). */
final class Familias
{
    /** @var array<string, class-string<Familia>> */
    public const TODAS = ['acta_vacunacion' => ActaVacunacion::class, 'dte' => Dte::class, 'guia_provincial' => GuiaProvincial::class, 'protocolo_sanitario' => ProtocoloSanitario::class];

    public static function get(string $codigo): Familia
    {
        $clase = self::TODAS[$codigo] ?? throw ApiException::validacion(__('Familia de documento desconocida.'), ['familia' => [__('Valor inválido.')]]);

        return app($clase);
    }
}
