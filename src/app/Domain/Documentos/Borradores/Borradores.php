<?php

namespace App\Domain\Documentos\Borradores;

use Illuminate\Support\Facades\DB;

/** Transiciones automáticas de estado (no las dispara una persona). */
final class Borradores
{
    /** Páginas del borrador (SHA-256 en orden). */
    public static function paginas(object $borrador): array
    {
        return DB::table('documento_version_archivos')->where('documento_version_id', $borrador->documento_version_id)->orderBy('orden')->pluck('archivo_sha256')->all();
    }

    /** `esperando_archivos` → `en_cola` (y se despacha la extracción) cuando todas las páginas están completas. */
    public static function encolarSiListo(string $id): void
    {
        $listo = DB::transaction(function () use ($id) {
            $b = DB::table('borradores_documento')->where('id', $id)->lockForUpdate()->first();
            if ($b === null || $b->estado !== 'esperando_archivos') {
                return false;
            }
            $paginas = self::paginas($b);
            $completas = DB::table('archivos')->whereIn('sha256', $paginas)->where('estado', 'completo')->count();
            if ($completas < count(array_unique($paginas))) {
                return false;
            }
            DB::table('borradores_documento')->where('id', $id)->update(['estado' => 'en_cola', 'updated_at' => now()]);

            return true;
        });
        if ($listo) {
            ExtraerBorradorJob::dispatch($id)->afterCommit();
        }
    }

    /** Hook de la subida diferida: una página terminó de subir. */
    public static function archivoCompleto(string $sha256): void
    {
        $ids = DB::table('borradores_documento as b')->join('documento_version_archivos as va', 'va.documento_version_id', '=', 'b.documento_version_id')
            ->where('b.estado', 'esperando_archivos')->where('va.archivo_sha256', $sha256)->distinct()->pluck('b.id');
        foreach ($ids as $id) {
            self::encolarSiListo($id);
        }
    }
}
