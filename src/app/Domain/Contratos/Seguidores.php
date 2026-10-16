<?php

namespace App\Domain\Contratos;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Notificaciones\Notificador;
use Illuminate\Support\Facades\DB;

/** Seguir productores (TPA-57): aviso de ofertas y contratos nuevos a quien sigue el establecimiento (solo con perfil publicado). */
final class Seguidores
{
    /** @param array<string, mixed> $datos */
    public static function avisar(Establecimiento $est, string $tipo, string $titulo, string $cuerpo, array $datos, string $clave): int
    {
        if (! DB::getSchemaBuilder()->hasTable('seguimientos') || ! DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->where('publicado', true)->exists()) {
            return 0;
        }
        $n = 0;
        foreach (DB::table('seguimientos')->where('establecimiento_id', $est->id)->whereNull('dejado_en')->pluck('usuario_id') as $u) {
            $n += app(Notificador::class)->aUsuario($u, null, $tipo, $titulo, $cuerpo, $datos, $clave) ? 1 : 0;
        }

        return $n;
    }
}
