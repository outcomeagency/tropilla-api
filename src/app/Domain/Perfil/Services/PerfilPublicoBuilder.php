<?php

namespace App\Domain\Perfil\Services;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Documentos\ConsultasDocumentos;
use App\Domain\Establecimientos\Data\Provincias;
use App\Domain\Integridad\Confianza;
use App\Http\Resources\V1\Publico\OfertaPublicaResource;
use App\Support\Formato;
use App\Support\Leyenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Perfil público (functional/05 §3) — **whitelist explícita**: nunca CUIT, RENSPA, fiscal, precios de operaciones,
 * ubicación exacta, reactores, tratamientos individuales ni nombres de empleados. Las secciones derivadas se calculan
 * en vivo (se actualizan solas) salvo las **congeladas**, que muestran su snapshot.
 */
final class PerfilPublicoBuilder
{
    public const SECCIONES = ['portada', 'actividad', 'rodeo', 'sanidad', 'trazabilidad', 'documentos', 'oferta', 'contacto'];

    /** @return array<string, mixed> PerfilPublico */
    public static function armar(object $perfil, string $locale): array
    {
        $est = DB::table('establecimientos')->where('id', $perfil->establecimiento_id)->first();
        $prod = DB::table('productores')->where('id', $est->productor_id)->first();
        $secciones = json_decode($perfil->secciones, true) ?: Perfiles::seccionesDefault();
        $textos = json_decode($perfil->textos, true) ?: [];
        $t = $textos[$locale] ?? $textos['es'] ?? [];

        $out = [];
        foreach (self::SECCIONES as $s) {
            $conf = $secciones[$s] ?? ['activa' => true];
            if (! ($conf['activa'] ?? true)) {
                $out[$s] = null;

                continue;
            }
            $out[$s] = ($conf['congelada_en'] ?? null) && isset($conf['snapshot'])
                ? $conf['snapshot']
                : self::seccion($s, $perfil, $est, $prod, $t, $locale, $conf);
        }
        $out['ofertas'] = $out['oferta'];
        unset($out['oferta']);
        $out['contratos'] = $out['ofertas'] === null ? null : [];
        $out['contacto'] ??= ['formulario' => true, 'email' => null, 'telefono' => null, 'whatsapp' => null];

        $traz = self::seccion('trazabilidad', $perfil, $est, $prod, $t, $locale, []);
        $insignias = [];
        if ($traz['historial_meses'] >= 12) {
            $insignias[] = 'historial_12_meses';
        }
        if ((float) $traz['pct_integridad_registrada'] >= 50) {
            $insignias[] = 'integridad_registrada';
        }
        $web = config('tropilla.web_url');
        $titulo = ($t['titulo'] ?? null) ?: $est->nombre;

        return [
            'slug' => $perfil->slug,
            'locale' => $locale,
            'nombre' => $est->nombre,
            'productor' => $prod->nombre,
            'secciones' => $out,
            'insignias' => $insignias,
            'actualizado_en' => Formato::fechaHora(Carbon::parse($perfil->updated_at)),
            'leyenda' => Leyenda::texto($locale),
            'seo' => [
                'titulo' => $titulo.' · TROPILLA',
                'descripcion' => mb_substr(($t['descripcion'] ?? '') ?: trim($est->nombre.' — '.OfertaPublicaResource::zona($est, $perfil)), 0, 160),
                'canonical' => "{$web}/{$locale}/p/{$perfil->slug}",
                'alternates' => ['es' => "{$web}/es/p/{$perfil->slug}", 'en' => "{$web}/en/p/{$perfil->slug}", 'pt' => "{$web}/pt/p/{$perfil->slug}"],
                'indexable' => (bool) ($perfil->publicado && $perfil->en_directorio),
                'json_ld' => ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $est->nombre, 'url' => "{$web}/{$locale}/p/{$perfil->slug}",
                    'address' => ['@type' => 'PostalAddress', 'addressRegion' => Provincias::TODAS[$est->provincia_codigo] ?? '', 'addressCountry' => 'AR']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $t  textos del idioma
     * @param  array<string, mixed>  $conf
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function seccion(string $s, object $perfil, object $est, object $prod, array $t, string $locale, array $conf): array
    {
        $nombre = fn (string $tabla, string $codigo) => Catalogos::nombre(DB::table($tabla)->where('codigo', $codigo)->value('nombres'), $locale) ?? $codigo;
        $item = fn (string $lista, string $codigo) => Catalogos::nombre(DB::table('catalogo_items')->where('lista', $lista)->where('codigo', $codigo)->value('nombres'), $locale) ?? $codigo;
        $arr = fn (?string $pg) => array_values(array_filter(explode(',', trim((string) $pg, '{}'))));

        return match ($s) {
            'portada' => [
                'historia' => ($t['historia'] ?? null) ?: $est->historia,
                'fotos' => [],
                'zona' => [
                    'provincia' => Provincias::TODAS[$est->provincia_codigo] ?? '',
                    'departamento' => $perfil->ubicacion_publica === 'provincia' ? null : DB::table('geo_departamentos')->where('codigo', $est->departamento_codigo)->value('nombre'),
                    'localidad' => $perfil->ubicacion_publica === 'localidad' ? $est->localidad : null,
                ],
                'asociaciones' => $perfil->asociaciones_visibles ? array_map(fn ($a) => ['entidad' => $a['entidad']], json_decode($prod->asociaciones, true) ?: []) : [],
            ],
            'actividad' => [
                'actividades' => array_map(fn ($c) => ['codigo' => $c, 'nombre' => $item('actividades', $c)], $arr($est->actividades)),
                'especies' => array_map(fn ($c) => ['codigo' => $c, 'nombre' => $nombre('especies', $c)], $arr($est->especies)),
                'razas' => DB::table('animales_estado')->where('establecimiento_id', $est->id)->where('estado', 'activo')->whereNotNull('raza_codigo')
                    ->distinct()->orderBy('raza_codigo')->pluck('raza_codigo')->map(fn ($c) => ['codigo' => $c, 'nombre' => $nombre('razas', $c)])->all(),
                'superficie_ha' => Formato::decimal($est->superficie_ha),
                'ambiente' => implode(', ', DB::table('potreros')->where('establecimiento_id', $est->id)->whereNull('archivado_en')->whereNotNull('ambiente')
                    ->distinct()->orderBy('ambiente')->pluck('ambiente')->map(fn ($a) => $item('ambientes', $a))->all()) ?: null,
            ],
            'rodeo' => self::rodeo($est->id, $conf['modo'] ?? 'rango', $locale),
            'sanidad' => ['ultimos_12_meses' => DB::table('eventos_vigentes')->where('establecimiento_id', $est->id)->where('accion', '<>', 'anulacion')
                ->where('tipo', 'sanidad.vacunacion')->where('ocurrido_en', '>=', now()->subYear())
                ->selectRaw("payload->>'enfermedad' as enfermedad, count(*) as n")->groupByRaw("payload->>'enfermedad'")->orderBy('enfermedad')->get()
                ->map(fn ($r) => ['enfermedad' => (string) $r->enfermedad, 'registros' => (int) $r->n,
                    'texto' => $item('enfermedades', (string) $r->enfermedad).': '.$r->n.' '.(['es' => 'registros de vacunación', 'en' => 'vaccination records', 'pt' => 'registros de vacinação'][$locale])])->all()],
            'trazabilidad' => self::trazabilidad($est->id),
            // Solo lo que el productor publicó explícitamente (visibilidad perfil); fiscal nunca (CHECK + trigger + no_publicable).
            'documentos' => DB::table('documentos as d')->join('documento_versiones as v', fn ($j) => $j->on('v.documento_id', '=', 'd.id')->on('v.version', '=', 'd.version_vigente')->where('v.en_revision', false))
                ->where('d.establecimiento_id', $est->id)->where('d.visibilidad', 'perfil')->where('d.estado', 'vigente')->where('d.privacidad', '<>', 'fiscal')
                ->orderBy('d.tipo_codigo')->orderByDesc('d.emitido_en')->get(['d.*', 'v.hash', 'v.registro_integridad_id'])
                ->map(fn ($d) => ['titulo' => $d->titulo, 'tipo' => $nombre('tipos_documento', $d->tipo_codigo), 'emisor_declarado' => $d->emisor_nombre,
                    'emitido_en' => $d->emitido_en, 'vence_en' => $d->vence_en, 'estado' => ConsultasDocumentos::estado($d, now()->toDateString()),
                    'confianza' => Confianza::deRegistro((int) $d->registro_integridad_id), 'verificacion_url' => config('tropilla.web_url').'/v/'.$d->hash])->all(),
            'oferta' => DB::table('ofertas')->where('establecimiento_id', $est->id)->whereIn('estado', ['publicada', 'reservada'])->orderByDesc('publicada_en')->get()
                ->map(fn ($o) => OfertaPublicaResource::resumen($o, $locale))->filter()->values()->all(),
            'contacto' => (function () use ($perfil, $prod) {
                $vis = json_decode($perfil->contacto_visible, true) ?: [];
                $c = json_decode($prod->contacto, true) ?: [];

                return ['formulario' => true, 'email' => ($vis['email'] ?? false) ? ($c['email'] ?? null) : null,
                    'telefono' => ($vis['telefono'] ?? false) ? ($c['telefono'] ?? null) : null, 'whatsapp' => ($vis['whatsapp'] ?? false) ? ($c['whatsapp'] ?? null) : null];
            })(),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function rodeo(string $est, string $modo, string $locale): array
    {
        $rango = fn (int $n) => match (true) {
            $n <= 10 => '1–10', $n <= 50 => '11–50', $n <= 100 => '51–100', $n <= 250 => '101–250',
            $n <= 500 => '251–500', $n <= 1000 => '501–1.000', $n <= 2500 => '1.001–2.500', default => '+2.500',
        };
        $porEspecie = [];
        $total = 0;
        foreach (DB::table('existencias as e')->join('categorias as c', 'c.codigo', '=', 'e.categoria_codigo')->where('e.establecimiento_id', $est)
            ->orderBy('e.especie_codigo')->orderBy('c.orden')->get(['e.*', 'c.nombres']) as $e) {
            $porEspecie[$e->especie_codigo]['categorias'][] = ['codigo' => $e->categoria_codigo, 'nombre' => Catalogos::nombre($e->nombres, $locale),
                ...($modo === 'exacto' ? ['cabezas' => (int) $e->cabezas] : ['rango' => $rango((int) $e->cabezas)])];
            $porEspecie[$e->especie_codigo]['n'] = ($porEspecie[$e->especie_codigo]['n'] ?? 0) + (int) $e->cabezas;
            $total += (int) $e->cabezas;
        }
        $lista = [];
        foreach ($porEspecie as $esp => $d) {
            $lista[] = ['especie' => $esp, 'nombre' => Catalogos::nombre(DB::table('especies')->where('codigo', $esp)->value('nombres'), $locale),
                ...($modo === 'exacto' ? ['cabezas' => $d['n']] : ['rango' => $rango($d['n'])]), 'categorias' => $d['categorias']];
        }
        $individuales = DB::table('animales_estado')->where('establecimiento_id', $est)->where('estado', 'activo')->count();
        $rfid = DB::table('animal_identificaciones as i')->join('animales_estado as a', 'a.animal_id', '=', 'i.animal_id')
            ->where('a.establecimiento_id', $est)->where('a.estado', 'activo')->where('i.tipo', 'rfid')->where('i.vigente', true)->distinct()->count('i.animal_id');

        return ['modo' => $modo, 'por_especie' => $lista,
            'pct_identificacion_individual' => $total ? Formato::decimal(number_format(100 * $individuales / $total, 1, '.', '')) : null,
            'pct_rfid' => $total ? Formato::decimal(number_format(100 * $rfid / $total, 1, '.', '')) : null];
    }

    /** @return array{eventos: int, historial_meses: int, pct_integridad_registrada: string} */
    public static function trazabilidad(string $est): array
    {
        $eventos = DB::table('eventos_vigentes')->where('establecimiento_id', $est)->where('accion', '<>', 'anulacion')->count();
        $primero = DB::table('eventos')->where('establecimiento_id', $est)->min('ocurrido_en');
        $anclados = DB::getSchemaBuilder()->hasTable('anclaje_inclusiones')
            ? DB::table('eventos_vigentes as v')->join('anclaje_inclusiones as i', 'i.registro_id', '=', 'v.registro_integridad_id')
                ->join('anclajes as a', 'a.id', '=', 'i.anclaje_id')->where('a.estado', 'confirmado')->where('v.establecimiento_id', $est)->distinct()->count('v.id')
            : 0;

        return ['eventos' => $eventos, 'historial_meses' => $primero ? (int) floor(Carbon::parse($primero)->diffInMonths(now(), true)) : 0,
            'pct_integridad_registrada' => (string) Formato::decimal(number_format($eventos ? 100 * $anclados / $eventos : 0, 1, '.', ''))];
    }

    /**
     * Completitud 0–100 y faltantes (ranking del directorio, nunca por pago).
     *
     * @return array{0: int, 1: list<string>}
     */
    public static function completitud(object $perfil): array
    {
        $est = DB::table('establecimientos')->where('id', $perfil->establecimiento_id)->first();
        $t = json_decode($perfil->textos, true) ?: [];
        $chequeos = [
            'titulo' => ! empty($t['es']['titulo']), 'descripcion' => ! empty($t['es']['descripcion']),
            'historia' => ! empty($t['es']['historia']) || ! empty($est->historia), 'idioma_extranjero' => ! empty($t['en']['descripcion']) || ! empty($t['pt']['descripcion']),
            'superficie' => $est->superficie_ha !== null, 'potreros' => DB::table('potreros')->where('establecimiento_id', $est->id)->exists(),
            'hacienda' => DB::table('existencias')->where('establecimiento_id', $est->id)->exists(),
            'historial' => DB::table('eventos')->where('establecimiento_id', $est->id)->count() >= 10,
            'fotos' => trim((string) $perfil->fotos, '{}') !== '',
            'oferta' => DB::table('ofertas')->where('establecimiento_id', $est->id)->whereIn('estado', ['publicada', 'reservada'])->exists(),
        ];
        $faltan = array_keys(array_filter($chequeos, fn ($ok) => ! $ok));

        return [(int) round(100 * (count($chequeos) - count($faltan)) / count($chequeos)), $faltan];
    }
}
