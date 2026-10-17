<?php

namespace App\Domain\Documentos;

use App\Support\Leyenda;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Fotos como objetos `Foto {sha256, url, alt, ancho, alto}` (bugfix TPA-54).
 * - **Públicas** (perfil publicado, oferta publicada/reservada/vendida, profesional público): URL estable por hash
 *   `GET /api/v1/public/fotos/{sha256}`, cacheable para siempre; la ruta solo sirve hashes que algo publicado referencia.
 * - **Privadas** (animal, usuario, vista interna de perfil/oferta): URL firmada `GET /api/v1/fotos/{sha256}?expires&signature`
 *   que vence al terminar la hora siguiente (estable dentro de la hora: el navegador la cachea).
 */
final class Fotos
{
    /** @return array<string, mixed>|null */
    public static function publica(?string $sha, ?string $locale = null): ?array
    {
        return $sha ? (self::lista([$sha], true, $locale)[0] ?? null) : null;
    }

    /** @return array<string, mixed>|null */
    public static function privada(?string $sha, ?string $locale = null): ?array
    {
        return $sha ? (self::lista([$sha], false, $locale)[0] ?? null) : null;
    }

    /**
     * Solo archivos completos; respeta el orden recibido. Una consulta para toda la lista.
     *
     * @param  iterable<string>|null  $shas
     * @return list<array<string, mixed>>
     */
    public static function lista(?iterable $shas, bool $publica, ?string $locale = null): array
    {
        $shas = array_values(array_unique(array_filter(is_array($shas) ? $shas : iterator_to_array($shas ?? []), fn ($s) => is_string($s) && preg_match('/^[0-9a-f]{64}$/', $s))));
        if ($shas === []) {
            return [];
        }
        $locale = Leyenda::locale($locale);
        $filas = DB::table('archivos')->whereIn('sha256', $shas)->where('estado', 'completo')->get(['sha256', 'ancho', 'alto', 'alt'])->keyBy('sha256');
        $out = [];
        foreach ($shas as $sha) {
            if (! $f = $filas->get($sha)) {
                continue;
            }
            $alt = json_decode((string) $f->alt, true) ?: [];
            $out[] = [
                'sha256' => $sha,
                'url' => $publica ? self::urlPublica($sha) : self::urlFirmada($sha),
                'alt' => $alt[$locale] ?? $alt['es'] ?? null,
                'ancho' => $f->ancho === null ? null : (int) $f->ancho,
                'alto' => $f->alto === null ? null : (int) $f->alto,
            ];
        }

        return $out;
    }

    public static function urlPublica(string $sha): string
    {
        return rtrim((string) config('app.url'), '/').route('v1.public.fotos.show', ['sha256' => $sha], false);
    }

    public static function urlFirmada(string $sha): string
    {
        // Firma relativa (sin host): la misma URL vale detrás del proxy de la web o desde la app.
        return rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('v1.fotos.firmada', now()->startOfHour()->addHours(2), ['sha256' => $sha], false);
    }

    /** ¿Lo referencia algo publicado? (perfil publicado, publicación vigente de una oferta visible, profesional público) */
    public static function esPublica(string $sha): bool
    {
        return DB::table('perfiles_publicos')->where('publicado', true)->whereRaw('? = ANY(fotos)', [$sha])->exists()
            || DB::table('perfiles_publicos')->where('publicado', true)->whereRaw("(secciones->'reproductores'->>'activa' = 'true' OR secciones->'galeria'->>'activa' = 'true') AND EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(secciones->'reproductores'->'ids', '[]'::jsonb)) r WHERE r->>'tipo' = 'interno' AND EXISTS (SELECT 1 FROM animales_estado a WHERE a.animal_id::text = r->>'id' AND a.foto_sha256 = ?))", [$sha])->exists()
            || DB::table('profesionales')->where('publico', true)->where('foto_sha256', $sha)->exists()
            || DB::table('ofertas as o')->join('oferta_publicaciones as op', fn ($j) => $j->on('op.oferta_id', '=', 'o.id')->on('op.version', '=', 'o.publicacion_vigente'))
                ->whereIn('o.estado', ['publicada', 'reservada', 'vendida'])->whereRaw("jsonb_exists(op.contenido->'fotos', ?)", [$sha])->exists();
    }

    /** @return array{0: ?int, 1: ?int} ancho y alto si los bytes son una imagen */
    public static function dimensiones(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        return $info ? [(int) $info[0], (int) $info[1]] : [null, null];
    }
}
