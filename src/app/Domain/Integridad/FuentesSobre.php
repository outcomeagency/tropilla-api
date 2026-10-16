<?php

namespace App\Domain\Integridad;

use Illuminate\Support\Facades\DB;

/**
 * De dónde sale el **sobre guardado** de cada tipo de sujeto, para recalcular la huella (S8:
 * el servidor guarda el sobre recibido y recalcula desde ahí, nunca desde columnas tipadas).
 */
final class FuentesSobre
{
    /** sujeto_tipo => [tabla, columna del sobre] */
    public const TABLAS = [
        'evento' => ['eventos', 'sobre'],
        'documento_version' => ['documento_versiones', 'sobre'],
        'contrato_version' => ['contrato_versiones', 'sobre'],
        'toma_movimiento' => ['toma_movimientos', 'sobre'],
        'oferta_publicacion' => ['oferta_publicaciones', 'sobre'],
        'operacion_version' => ['operacion_versiones', 'sobre'],
        'consentimiento' => ['consentimientos', 'sobre'],
        'perfil_publicacion' => ['perfil_publicaciones', 'sobre'],
        'entrega' => ['entregas', 'sobre'],
    ];

    /** Sobre como texto JSON (jsonb::text conserva {} vs [] y strings exactos), o null si no existe. */
    public static function sobre(string $sujetoTipo, string $sujetoId): ?string
    {
        [$tabla, $columna] = self::TABLAS[$sujetoTipo] ?? [null, null];
        if ($tabla === null || ! DB::getSchemaBuilder()->hasTable($tabla)) {
            return null;
        }

        return DB::table($tabla)->where('id', $sujetoId)->value(DB::raw("{$columna}::text"));
    }
}
