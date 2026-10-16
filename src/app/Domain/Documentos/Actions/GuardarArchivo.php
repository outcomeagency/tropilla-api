<?php

namespace App\Domain\Documentos\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Guarda bytes direccionados por contenido (modelo-datos §10): clave `archivos/sha256/aa/bb/<hash>`,
 * una sola vez por hash (nunca se sobrescribe). Base mínima para la planilla de TPA-21; TPA-44 agrega
 * la subida diferida por partes y URLs prefirmadas.
 */
final class GuardarArchivo
{
    public function handle(string $contenido, string $mime, User $usuario): string
    {
        $sha = hash('sha256', $contenido);
        $clave = sprintf('archivos/sha256/%s/%s/%s', substr($sha, 0, 2), substr($sha, 2, 2), $sha);

        if (! DB::table('archivos')->where('sha256', $sha)->where('estado', 'completo')->exists()) {
            if (! Storage::exists($clave)) {
                Storage::put($clave, $contenido);
            }
            DB::table('archivos')->updateOrInsert(['sha256' => $sha], [
                'bytes' => strlen($contenido), 'mime' => $mime, 's3_clave' => $clave, 'estado' => 'completo',
                'subido_por' => $usuario->id, 'subido_en' => now(), 'verificado_en' => now(),
            ]);
        }

        return $sha;
    }

    public static function contenido(string $sha): ?string
    {
        $clave = DB::table('archivos')->where('sha256', $sha)->where('estado', 'completo')->value('s3_clave');

        return $clave && Storage::exists($clave) ? Storage::get($clave) : null;
    }
}
