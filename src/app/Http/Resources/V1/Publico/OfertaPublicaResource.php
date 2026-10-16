<?php

namespace App\Http\Resources\V1\Publico;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Documentos\Fotos;
use App\Domain\Establecimientos\Data\Provincias;
use App\Domain\Integridad\Confianza;
use App\Support\Formato;
use App\Support\Leyenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Schema `OfertaPublica` — **clase separada de la privada** (api-v1 §5.2): whitelist explícita. Sale de la última
 * publicación congelada (lo que se ancló es lo que se mostró). Nunca: CUIT, RENSPA, ubicación exacta, reactores,
 * precio (salvo `mostrar_precio`), datos de compradores.
 */
final class OfertaPublicaResource
{
    /**
     * @param  array<string, mixed>  $contenido
     * @return array<string, mixed>
     */
    public static function armar(object $oferta, array $contenido, string $huella, int $version, string $locale, ?string $actualizado = null, ?int $registroId = null): array
    {
        $est = DB::table('establecimientos')->where('id', $oferta->establecimiento_id)->first();
        $perfil = DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->first();
        $nombre = fn (string $tabla, string $codigo) => Catalogos::nombre(DB::table($tabla)->where('codigo', $codigo)->value('nombres'), $locale) ?? $codigo;

        $c = $contenido;
        $c['categorias'] = array_map(fn ($x) => [...$x, 'nombre' => $nombre('categorias', $x['codigo'])], $c['categorias'] ?? []);
        $c['razas'] = array_map(fn ($x) => [...$x, 'nombre' => $nombre('razas', $x['codigo'])], $c['razas'] ?? []);
        $textos = (array) ($c['textos'] ?? []);
        $t = (array) ($textos[$locale] ?? $textos['es'] ?? []);
        unset($c['textos']);
        $c['fotos'] = Fotos::lista($c['fotos'] ?? [], true, $locale); // bugfix TPA-54: hashes → Foto[]
        $c['titulo'] = $t['titulo'] ?? '';
        $c['descripcion'] = $t['descripcion'] ?? '';

        $web = config('tropilla.web_url');
        $titulo = trim(($t['titulo'] ?? '').' · '.$est->nombre);

        return [
            'codigo' => $oferta->codigo_publico,
            'version' => $version,
            'estado' => $oferta->estado,
            'locale' => $locale,
            'establecimiento' => ['nombre' => $est->nombre, 'slug' => $perfil?->publicado ? $perfil->slug : '', 'zona' => self::zona($est, $perfil)],
            'contenido' => $c,
            'huella' => $huella,
            'confianza' => Confianza::deRegistro($registroId),
            'verificacion_url' => "{$web}/v/{$huella}",
            'pdf_url' => rtrim((string) config('app.url'), '/')."/api/v1/public/ofertas/{$oferta->codigo_publico}/pdf?locale={$locale}",
            'actualizado_en' => Formato::fechaHora(Carbon::parse($actualizado ?? now())),
            'leyenda' => Leyenda::texto($locale),
            'seo' => [
                'titulo' => $titulo,
                'descripcion' => mb_substr(($t['descripcion'] ?? '') ?: $titulo, 0, 160),
                'canonical' => "{$web}/{$locale}/o/{$oferta->codigo_publico}",
                'alternates' => ['es' => "{$web}/es/o/{$oferta->codigo_publico}", 'en' => "{$web}/en/o/{$oferta->codigo_publico}", 'pt' => "{$web}/pt/o/{$oferta->codigo_publico}"],
                'indexable' => (bool) ($perfil?->publicado && $perfil?->en_directorio),
                'json_ld' => ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $titulo,
                    'offers' => ['@type' => 'Offer', 'availability' => $oferta->estado === 'publicada' ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut']],
            ],
        ];
    }

    /** Zona general según `ubicacion_publica` (la ubicación exacta nunca sale en MVP, M16). */
    public static function zona(object $est, ?object $perfil): string
    {
        $prov = Provincias::TODAS[$est->provincia_codigo] ?? '';
        $depto = DB::table('geo_departamentos')->where('codigo', $est->departamento_codigo)->value('nombre');

        return match ($perfil->ubicacion_publica ?? 'departamento') {
            'provincia' => $prov,
            'localidad' => trim(implode(', ', array_filter([$est->localidad, $depto, $prov])), ', '),
            default => trim(implode(', ', array_filter([$depto, $prov])), ', '),
        };
    }

    /** OfertaPublicaResumen para listas (perfil, directorio). @return array<string, mixed> */
    public static function resumen(object $oferta, string $locale): ?array
    {
        $pub = DB::table('oferta_publicaciones')->where('oferta_id', $oferta->id)->orderByDesc('version')->first();
        if ($pub === null) {
            return null;
        }
        $c = json_decode($pub->contenido, true);
        $t = (array) (($c['textos'] ?? [])[$locale] ?? ($c['textos']['es'] ?? []));

        return [
            'codigo' => $oferta->codigo_publico, 'titulo' => $t['titulo'] ?? '', 'especie' => $c['especie'], 'cantidad' => (int) $c['cantidad'],
            'categorias' => array_column($c['categorias'] ?? [], 'codigo'), 'peso_promedio_kg' => $c['peso']['promedio_kg'] ?? null,
            'estado' => $oferta->estado, 'foto_url' => ($foto = Fotos::publica($c['fotos'][0] ?? null, $locale))['url'] ?? null, 'foto' => $foto, 'url' => config('tropilla.web_url')."/{$locale}/o/{$oferta->codigo_publico}",
        ];
    }
}
