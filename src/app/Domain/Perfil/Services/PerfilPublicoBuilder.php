<?php

namespace App\Domain\Perfil\Services;

use App\Casts\PgTextArray;
use App\Domain\Catalogos\Catalogos;
use App\Domain\Contratos\ConsultasContratos;
use App\Domain\Documentos\ConsultasDocumentos;
use App\Domain\Documentos\Fotos;
use App\Domain\Establecimientos\Data\Provincias;
use App\Domain\Integridad\Confianza;
use App\Http\Resources\V1\Publico\OfertaPublicaResource;
use App\Support\Formato;
use App\Support\Leyenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Perfil público (functional/05 §3) — **whitelist explícita**: nunca CUIT, identificador RENSPA, fiscal, precios de operaciones,
 * ubicación exacta, reactores, tratamientos individuales ni nombres de empleados. Las secciones derivadas se calculan
 * en vivo (se actualizan solas) salvo las **congeladas**, que muestran su snapshot.
 */
final class PerfilPublicoBuilder
{
    public const SECCIONES = ['portada', 'actividad', 'rodeo', 'sanidad', 'trazabilidad', 'documentos', 'oferta', 'contacto', 'reproductores', 'composicion', 'indicadores', 'galeria'];

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
            $conf = $secciones[$s] ?? ['activa' => ! in_array($s, ['reproductores', 'composicion', 'indicadores', 'galeria'], true)];
            if (! ($conf['activa'] ?? true)) {
                $out[$s] = null;

                continue;
            }
            $out[$s] = ($conf['congelada_en'] ?? null) && isset($conf['snapshot'])
                ? $conf['snapshot']
                : self::seccion($s, $perfil, $est, $prod, $t, $locale, $conf);
        }
        $out['ficha_establecimiento'] = self::fichaEstablecimiento($est);
        // Los bloques nuevos son opt-in; la selección de reproductores vive dentro de su configuración.
        foreach (['reproductores', 'composicion', 'indicadores', 'galeria'] as $nuevo) {
            if (! (($secciones[$nuevo]['activa'] ?? false) === true)) {
                $out[$nuevo] = null;
            }
        }
        $out['ofertas'] = $out['oferta'];
        unset($out['oferta']);
        // Contratos a futuro abiertos (TPA-56): sin precios (son solo para compradores con cuenta, ver /public/contratos/{codigo}).
        $out['contratos'] = $out['ofertas'] === null ? null : DB::table('contratos_futuro')->where('establecimiento_id', $perfil->establecimiento_id)
            ->whereIn('estado', ['publicado', 'parcialmente_tomado', 'completo'])->orderBy('fecha_entrega')->get()
            ->map(fn ($c) => ConsultasContratos::publico($c, false, $locale))->all();
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

    /** @return array<string, array<string, mixed>> Publica solo opt-in y campos no sensibles. */
    private static function fichaEstablecimiento(object $est): array
    {
        $row = DB::table('establecimientos')->where('id', $est->id)->first(['ficha_ampliada', 'visibilidad_ficha']);
        $ficha = json_decode((string) $row->ficha_ampliada, true) ?: [];
        $visibilidad = json_decode((string) $row->visibilidad_ficha, true) ?: [];
        $permitidos = [
            'sanidad_renspa' => ['renspa_vencimiento', 'senasa_vencimiento', 'plan_sanitario'],
            'potreros_aguadas' => ['potreros', 'aguadas', 'rotacion', 'fuentes_agua'],
            'sanidad' => ['manejo', 'vacunaciones_actualizadas', 'fecha'],
            'personal' => ['equipo'],
            'marcas_senales' => ['marca', 'senal', 'registro'],
            'suelo_pasturas' => ['suelos', 'pasturas', 'ambientes', 'manejo'],
            'capacidad_carga' => ['carga_ug_ha', 'periodo', 'criterio'],
            'infraestructura' => ['manga', 'balanza', 'alambrados', 'energia'],
            'clima' => ['precipitacion_media_mm', 'temperatura_media_c', 'fuente', 'fecha'],
            'logistica' => ['distancia_ruta_km', 'distancia_puerto_km', 'accesos', 'carga'],
            'certificaciones' => ['nombre', 'emisor', 'vence', 'alcance'],
        ];
        $salida = [];
        foreach ($permitidos as $bloque => $campos) {
            if (($visibilidad[$bloque] ?? false) !== true || ! is_array($ficha[$bloque] ?? null)) {
                continue;
            }
            $datos = array_filter(array_intersect_key($ficha[$bloque], array_flip($campos)), static fn ($valor) => is_string($valor) || is_numeric($valor));
            if ($datos !== []) {
                $salida[$bloque] = $datos;
            }
        }

        return $salida;
    }

    /** @param list<array{tipo:string,id:string}> $seleccion */
    private static function reproductoresPublicos(string $est, array $seleccion, string $locale): array
    {
        $salida = [];
        foreach (array_slice($seleccion, 0, 24) as $ref) {
            if (! is_array($ref) || ! isset($ref['tipo'], $ref['id'])) {
                continue;
            }
            $salida[] = self::nodoReproductor($est, (string) $ref['tipo'], (string) $ref['id'], 0, [], $locale);
        }

        return array_values(array_filter($salida));
    }

    /** Allowlist deliberada: no caravanas, RENSPA ni procedencia del campo de origen. */
    private static function nodoReproductor(string $est, string $tipo, string $id, int $nivel, array $ruta, string $locale): ?array
    {
        if ($nivel > 5 || ! in_array($tipo, ['interno', 'externo'], true) || in_array($tipo.':'.$id, $ruta, true)) {
            return null;
        }
        $ruta[] = $tipo.':'.$id;
        if ($tipo === 'externo') {
            $a = DB::table('reproductores_externos')->where('establecimiento_id', $est)->where('id', $id)->whereNull('archivado_en')->first();
            if (! $a) {
                return null;
            }
            $base = ['tipo' => 'externo', 'nombre' => $a->nombre, 'rp' => $a->rp, 'registro' => $a->registro_genealogico,
                'raza' => $a->raza, 'especie' => $a->especie_codigo, 'sexo' => $a->sexo ?? null, 'centro' => $a->centro, 'fecha_nacimiento' => $a->fecha_nacimiento,
                'peso_kg' => $nivel === 0 ? $a->peso_kg : null, 'peso_fecha' => $nivel === 0 ? $a->peso_fecha : null,
                'condicion_corporal' => $nivel === 0 ? $a->cc_valor : null, 'partos' => $nivel === 0 ? $a->partos : null,
                'crias' => $nivel === 0 ? DB::table('reproductores_externos')->where('establecimiento_id', $est)->where(fn ($q) => $q->where('madre_externa_id', $id)->orWhere('padre_externa_id', $id))->count() : null, 'foto' => null];
            $madre = $a->madre_id ? ['interno', $a->madre_id] : ($a->madre_externa_id ? ['externo', $a->madre_externa_id] : null);
            $padre = $a->padre_id ? ['interno', $a->padre_id] : ($a->padre_externa_id ? ['externo', $a->padre_externa_id] : null);
        } else {
            $a = DB::table('animales_estado as s')->join('animales as a', 'a.id', '=', 's.animal_id')->where('s.establecimiento_id', $est)->where('s.animal_id', $id)->where('s.estado', 'activo')->first();
            if (! $a) {
                return null;
            }
            $rp = DB::table('animal_identificaciones')->where('animal_id', $id)->where('tipo', 'registro_genealogico')->where('vigente', true)->value('valor');
            $nombre = $rp ?: (['es' => 'Animal del rodeo', 'en' => 'Herd animal', 'pt' => 'Animal do rebanho'][$locale] ?? 'Animal del rodeo');
            $base = ['tipo' => 'interno', 'nombre' => $nombre, 'rp' => $rp, 'registro' => null,
                'raza' => $a->raza_codigo, 'especie' => $a->especie_codigo, 'sexo' => $a->sexo, 'centro' => null, 'fecha_nacimiento' => $a->fecha_nacimiento,
                'peso_kg' => $nivel === 0 ? $a->peso_kg : null, 'peso_fecha' => $nivel === 0 ? $a->peso_fecha : null,
                'condicion_corporal' => $nivel === 0 ? $a->cc_valor : null, 'partos' => $nivel === 0 ? (int) $a->partos : null,
                'crias' => $nivel === 0 ? DB::table('animales_estado')->where('establecimiento_id', $est)->where(fn ($q) => $q->where('madre_id', $id)->orWhere('padre_id', $id))->count() : null,
                'foto' => $nivel === 0 ? Fotos::publica($a->foto_sha256, $locale) : null];
            $madre = $a->madre_id ? ['interno', $a->madre_id] : ($a->madre_externa_id ? ['externo', $a->madre_externa_id] : null);
            $padre = $a->padre_id ? ['interno', $a->padre_id] : ($a->padre_externa_id ? ['externo', $a->padre_externa_id] : null);
        }
        $base['madre'] = $madre ? self::nodoReproductor($est, $madre[0], $madre[1], $nivel + 1, $ruta, $locale) : null;
        $base['padre'] = $padre ? self::nodoReproductor($est, $padre[0], $padre[1], $nivel + 1, $ruta, $locale) : null;

        return $base;
    }

    private static function composicionPublica(string $est, string $modo, string $locale): array
    {
        $r = self::rodeo($est, $modo === 'exacto' ? 'exacto' : 'rango', $locale);
        $razas = DB::table('animales_estado')->where('establecimiento_id', $est)->where('estado', 'activo')->whereNotNull('raza_codigo')
            ->select('especie_codigo', 'raza_codigo')->selectRaw('count(*) as cabezas')->groupBy('especie_codigo', 'raza_codigo')->orderBy('especie_codigo')->orderBy('raza_codigo')->get();

        return ['por_especie' => $r['por_especie'], 'razas' => $razas->map(fn ($x) => ['especie' => $x->especie_codigo, 'raza' => $x->raza_codigo,
            'cantidad' => $modo === 'exacto' ? (int) $x->cabezas : null])->all()];
    }

    /** @param array<string, mixed> $conf @return list<array<string, mixed>> */
    private static function galeriaPublica(object $perfil, array $conf, string $locale): array
    {
        $shas = PgTextArray::parse($perfil->fotos) ?? [];
        foreach ($conf['ids'] ?? [] as $ref) {
            if (($ref['tipo'] ?? null) !== 'interno' || ! isset($ref['id'])) {
                continue;
            }
            $sha = DB::table('animales_estado')->where('animal_id', $ref['id'])->where('establecimiento_id', $perfil->establecimiento_id)->value('foto_sha256');
            if ($sha) {
                $shas[] = $sha;
            }
        }

        return Fotos::lista(array_values(array_unique($shas)), true, $locale);
    }

    private static function indicadoresPublicos(object $establecimiento, string $locale): array
    {
        $est = $establecimiento->id;
        $inicio = $establecimiento->created_at;
        $ultimoTacto = DB::table('animales_estado')->where('establecimiento_id', $est)->whereNotNull('tacto_fecha')->max('tacto_fecha');
        $tactos = $ultimoTacto ? DB::table('animales_estado')->where('establecimiento_id', $est)->whereDate('tacto_fecha', $ultimoTacto)->whereIn('estado_reproductivo', ['preñada', 'vacia'])->get(['estado_reproductivo']) : collect();
        $evaluados = $tactos->count();
        $preñadas = $tactos->where('estado_reproductivo', 'preñada')->count();
        $partos = DB::table('eventos_vigentes')->where('establecimiento_id', $est)->where('tipo', 'reproduccion.parto')->where('ocurrido_en', '>=', now()->subYears(3)->toDateString())->selectRaw('extract(year from ocurrido_en)::int as temporada, count(*) as cantidad, max(ocurrido_en)::date as fecha')->groupBy('temporada')->orderBy('temporada')->get();
        $fuente = ['es' => 'Registros de manejo cargados en TROPILLA', 'en' => 'Management records entered in TROPILLA', 'pt' => 'Registros de manejo lançados no TROPILLA'][$locale];
        $ficha = is_array($establecimiento->ficha_ampliada ?? null) ? $establecimiento->ficha_ampliada : (json_decode((string) ($establecimiento->ficha_ampliada ?? '{}'), true) ?: []);
        $vis = is_array($establecimiento->visibilidad_ficha ?? null) ? $establecimiento->visibilidad_ficha : (json_decode((string) ($establecimiento->visibilidad_ficha ?? '{}'), true) ?: []);
        $plan = ($vis['sanidad_renspa'] ?? false) === true ? ($ficha['sanidad_renspa']['plan_sanitario'] ?? null) : null;
        $fechaPlan = $ficha['sanidad_renspa']['fecha'] ?? null;

        return ['prenez_ultimo_tacto' => $evaluados ? ['porcentaje' => round(100 * $preñadas / $evaluados, 1), 'evaluados' => $evaluados, 'fecha' => $ultimoTacto, 'fuente' => $fuente] : null,
            'partos_por_temporada' => $partos->map(fn ($x) => ['temporada' => (int) $x->temporada, 'partos' => (int) $x->cantidad, 'fuente' => $fuente, 'fecha' => (string) $x->fecha])->all(),
            'anios_en_tropilla' => $inicio ? max(0, (int) floor(Carbon::parse($inicio)->diffInYears(now(), true))) : 0,
            'antiguedad_fuente' => ['fuente' => ['es' => 'Fecha de alta del establecimiento en TROPILLA', 'en' => 'Farm registration date in TROPILLA', 'pt' => 'Data de cadastro do estabelecimento no TROPILLA'][$locale], 'fecha' => Carbon::parse($inicio)->toDateString()],
            'plan_sanitario' => $plan && $fechaPlan ? ['texto' => $plan, 'fuente' => ['es' => 'Ficha declarada por el productor', 'en' => 'Farm details declared by the producer', 'pt' => 'Ficha declarada pelo produtor'][$locale], 'fecha' => $fechaPlan] : null];
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
                'fotos' => Fotos::lista(PgTextArray::parse($perfil->fotos), true, $locale), // bugfix TPA-54: Foto[] públicas
                'zona' => [
                    'provincia' => Provincias::TODAS[$est->provincia_codigo] ?? '',
                    'departamento' => $perfil->ubicacion_publica === 'provincia' ? null : DB::table('geo_departamentos')->where('codigo', $est->departamento_codigo)->value('nombre'),
                    'localidad' => $perfil->ubicacion_publica === 'localidad' ? $est->localidad : null,
                ],
                'asociaciones' => $perfil->asociaciones_visibles ? array_map(fn ($a) => ['entidad' => $a['entidad']], json_decode($prod->asociaciones, true) ?: []) : [],
            ],
            'reproductores' => self::reproductoresPublicos($est->id, $conf['ids'] ?? [], $locale),
            'composicion' => self::composicionPublica($est->id, $conf['modo'] ?? 'rango', $locale),
            'indicadores' => self::indicadoresPublicos($est, $locale),
            'galeria' => ['fotos' => self::galeriaPublica($perfil, $conf, $locale)],
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
