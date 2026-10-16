<?php

namespace App\Domain\Documentos;

use App\Domain\Establecimientos\Enums\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ¿El usuario puede ver algo que referencia este archivo? (api-v1: `GET /archivos/{sha256}`). Versiones de documento
 * (fiscal solo con `documentos.fiscal`), adjuntos de eventos (`hacienda.ver`), planillas de importación propias o lo que
 * subió él mismo. Si no, 404 (no se filtra existencia).
 */
final class AccesoArchivos
{
    public static function puedeVer(User $u, string $sha): bool
    {
        if (DB::table('archivos')->where('sha256', $sha)->where('subido_por', $u->id)->exists()) {
            return true;
        }
        $membresias = DB::table('membresias')->where('usuario_id', $u->id)->where('estado', 'activa')->pluck('rol', 'establecimiento_id');
        if ($membresias->isEmpty()) {
            return false;
        }
        $con = fn (string $ability) => $membresias->filter(fn ($rol) => (Rol::tryFrom((string) $rol)?->puede($ability)) ?? false)->keys()->all();

        $docs = DB::table('documento_version_archivos as a')->join('documento_versiones as v', 'v.id', '=', 'a.documento_version_id')
            ->where('a.archivo_sha256', $sha)->whereIn('v.establecimiento_id', $con('documentos.ver'))->get(['v.establecimiento_id', 'v.privacidad']);
        foreach ($docs as $d) {
            if ($d->privacidad !== 'fiscal' || in_array($d->establecimiento_id, $con('documentos.fiscal'), true)) {
                return true;
            }
        }
        // Foto de un animal visible (bugfix TPA-54).
        if (DB::table('animales_estado')->whereIn('establecimiento_id', $con('hacienda.ver'))->where('foto_sha256', $sha)->exists()) {
            return true;
        }
        if (DB::table('eventos')->whereIn('establecimiento_id', $con('hacienda.ver'))->whereRaw('? = ANY(adjuntos)', [$sha])->exists()) {
            return true;
        }

        return DB::getSchemaBuilder()->hasColumn('importaciones', 'archivo_sha256')
            && DB::table('importaciones')->where('archivo_sha256', $sha)->whereIn('establecimiento_id', $con('importaciones.gestionar'))->exists();
    }
}
