<?php

namespace App\Domain\Documentos\Almacen;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Almacén local (dev sin MinIO y tests): las «URLs prefirmadas» son rutas firmadas de la propia API
 * (`PUT /api/v1/archivos/subidas/{subida}/partes/{n}`) que guardan cada parte en `subidas/<id>/<n>`; completar las ensambla.
 */
final class LocalAlmacen implements AlmacenObjetos
{
    public function urlSimple(string $subidaId, string $clave, string $mime, DateTimeInterface $expira): string
    {
        return URL::temporarySignedRoute('v1.archivos.parte', $expira, ['subida' => $subidaId, 'numero' => 1]);
    }

    public function iniciarMultipart(string $subidaId, string $clave, string $mime, int $partes, DateTimeInterface $expira): array
    {
        $urls = [];
        for ($n = 1; $n <= $partes; $n++) {
            $urls[$n] = URL::temporarySignedRoute('v1.archivos.parte', $expira, ['subida' => $subidaId, 'numero' => $n]);
        }

        return [$subidaId, $urls];
    }

    public function completarMultipart(string $subidaId, string $clave, string $uploadId, array $partes): void
    {
        usort($partes, fn ($a, $b) => $a['numero'] <=> $b['numero']);
        $this->ensamblar($subidaId, $clave, array_column($partes, 'numero'));
    }

    public function completarSimple(string $subidaId, string $clave): void
    {
        $this->ensamblar($subidaId, $clave, [1]);
    }

    /** @param list<int> $numeros */
    private function ensamblar(string $subidaId, string $clave, array $numeros): void
    {
        $contenido = '';
        foreach ($numeros as $n) {
            $p = "subidas/{$subidaId}/{$n}";
            if (! Storage::exists($p)) {
                return; // falta una parte: la verificación lo marca
            }
            $contenido .= Storage::get($p);
        }
        if (! Storage::exists($clave)) {
            Storage::put($clave, $contenido);
        }
        Storage::deleteDirectory("subidas/{$subidaId}");
    }

    public function abortar(string $subidaId, string $clave, ?string $uploadId): void
    {
        Storage::deleteDirectory("subidas/{$subidaId}");
    }

    public function huella(string $clave): ?array
    {
        if (! Storage::exists($clave)) {
            return null;
        }
        $c = (string) Storage::get($clave);

        return ['sha256' => hash('sha256', $c), 'bytes' => strlen($c)];
    }

    public function borrar(string $clave): void
    {
        Storage::delete($clave);
    }

    public function urlDescarga(string $clave, DateTimeInterface $expira): ?string
    {
        try {
            return Storage::temporaryUrl($clave, $expira);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Guarda una parte recibida por la ruta firmada; devuelve el ETag (md5, como S3). */
    public static function guardarParte(string $subidaId, int $numero, string $bytes): string
    {
        Storage::put("subidas/{$subidaId}/{$numero}", $bytes);

        return md5($bytes);
    }
}
