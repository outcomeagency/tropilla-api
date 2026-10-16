<?php

namespace App\Domain\Documentos;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Archivos direccionados por contenido (modelo-datos §10): la clave del objeto es el SHA-256, nunca se sobrescribe. */
final class Archivos
{
    public static function clave(string $sha256): string
    {
        return sprintf('archivos/sha256/%s/%s/%s', substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256);
    }

    /** Registra la referencia (pendiente si los bytes todavía no subieron: subida diferida, TPA-44). */
    public static function referenciar(string $sha256, ?int $bytes, ?string $mime, ?string $usuarioId): void
    {
        DB::table('archivos')->insertOrIgnore([
            'sha256' => $sha256, 'bytes' => $bytes, 'mime' => $mime, 's3_clave' => self::clave($sha256), 'estado' => 'pendiente',
            'subido_por' => $usuarioId, 'created_at' => now(),
        ]);
    }

    /** URL prefirmada de 5 minutos (null si el archivo no está completo o el disco no firma URLs). */
    public static function url(string $sha256): ?string
    {
        $a = DB::table('archivos')->where('sha256', $sha256)->where('estado', 'completo')->first();
        if ($a === null) {
            return null;
        }
        try {
            return Storage::temporaryUrl($a->s3_clave, now()->addMinutes(5));
        } catch (Throwable) {
            return null;
        }
    }
}
