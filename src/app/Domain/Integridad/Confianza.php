<?php

namespace App\Domain\Integridad;

use Illuminate\Support\Facades\DB;

/**
 * Nivel de confianza (modelo-datos.md §8): `integridad_registrada` si el registro está incluido en un
 * anclaje **confirmado**; si no, `declarado`. Nunca «certificado» ni «verificado».
 */
final class Confianza
{
    public static function deRegistro(?int $registroId): string
    {
        if ($registroId === null || ! DB::getSchemaBuilder()->hasTable('anclaje_inclusiones')) {
            return 'declarado';
        }
        $ok = DB::table('anclaje_inclusiones as i')->join('anclajes as a', 'a.id', '=', 'i.anclaje_id')
            ->where('i.registro_id', $registroId)->where('a.estado', 'confirmado')->exists();

        return $ok ? 'integridad_registrada' : 'declarado';
    }
}
