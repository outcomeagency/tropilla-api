<?php

namespace App\Domain\Catalogos;

use App\Casts\PgTextArray;
use App\Domain\Establecimientos\Data\Provincias;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Paquete de catálogos globales (GET /catalogos y /sync/catalogos): especies, razas, categorías,
 * categorías de faena, reglas, parámetros globales, tipos de evento con su JSON Schema, tipos de
 * documento y de prueba, listas chicas, plantillas sanitarias y geografía. Versionado por
 * `catalogo_versiones` y cacheado por (versión, idioma).
 */
final class Catalogos
{
    public static function version(): int
    {
        return (int) (DB::table('catalogo_versiones')->max('version') ?? 0);
    }

    /** El caché va por versión: al subir la versión el paquete viejo deja de usarse solo. */
    public static function olvidar(): void
    {
        Cache::forget('catalogos:'.self::version().':es');
        Cache::forget('catalogos:'.self::version().':en');
        Cache::forget('catalogos:'.self::version().':pt');
    }

    /** @return array<string, mixed> */
    public static function paquete(string $locale): array
    {
        $version = self::version();

        return Cache::remember("catalogos:{$version}:{$locale}", 3600, fn () => self::armar($version, $locale));
    }

    /** Hash del contenido global (para decidir si sube la versión). */
    public static function huellaContenido(): string
    {
        return hash('sha256', json_encode(self::armar(0, 'es')));
    }

    /** Nombre traducido desde un `nombres` jsonb ({es, en, pt}), con fallback a es. */
    public static function nombre(mixed $nombres, string $locale): ?string
    {
        $n = is_string($nombres) ? json_decode($nombres, true) : (array) $nombres;

        return $n[$locale] ?? $n['es'] ?? null;
    }

    /** @return array<string, mixed> */
    private static function armar(int $version, string $locale): array
    {
        $j = fn ($v) => $v === null ? null : json_decode($v, true);
        $conNombre = fn ($fila) => ['nombre' => self::nombre($fila->nombres, $locale), 'nombres' => $j($fila->nombres)];

        return [
            'version' => $version,
            'especies' => DB::table('especies')->orderBy('orden')->get()->map(fn ($e) => [
                'codigo' => $e->codigo, ...$conNombre($e), 'nombre_cientifico' => $e->nombre_cientifico, 'activa' => (bool) $e->activa,
            ])->all(),
            'razas' => DB::table('razas')->whereNull('establecimiento_id')->orderBy('especie_codigo')->orderBy('orden')->get()->map(fn ($r) => [
                'codigo' => $r->codigo, 'especie' => $r->especie_codigo, ...$conNombre($r), 'prefijo_tatuaje' => $r->prefijo_tatuaje, 'activa' => (bool) $r->activa,
            ])->all(),
            'categorias' => DB::table('categorias')->orderBy('especie_codigo')->orderBy('orden')->get()->map(fn ($c) => [
                'codigo' => $c->codigo, 'especie' => $c->especie_codigo, ...$conNombre($c), 'sexo' => $c->sexo,
                'castrado' => $c->castrado === null ? null : (bool) $c->castrado, 'es_cria' => (bool) $c->es_cria,
                'es_vientre' => (bool) $c->es_vientre, 'es_reproductor' => (bool) $c->es_reproductor, 'orden' => (int) $c->orden,
            ])->all(),
            'categorias_faena' => DB::table('categorias_faena')->orderBy('id')->get()->map(fn ($c) => [
                'codigo' => $c->codigo, 'especie' => $c->especie_codigo, 'abreviatura' => $c->abreviatura, ...$conNombre($c),
                'sexo' => $c->sexo, 'denticion' => $c->denticion_desde, 'categorias_manejo' => PgTextArray::parse($c->categorias_manejo),
                'liviano_hasta_kg' => $c->liviano_hasta_kg === null ? null : rtrim(rtrim($c->liviano_hasta_kg, '0'), '.'), 'norma' => $c->norma,
            ])->all(),
            'reglas_categoria' => DB::table('reglas_categoria')->whereNull('establecimiento_id')->where('activa', true)
                ->orderBy('especie_codigo')->orderByDesc('prioridad')->get()->map(fn ($r) => [
                    'id' => (int) $r->id, 'especie' => $r->especie_codigo, 'categoria' => $r->categoria_codigo,
                    'prioridad' => (int) $r->prioridad, 'condiciones' => $j($r->condiciones), 'motivo' => $r->motivo, 'version' => (int) $r->version,
                ])->all(),
            'parametros' => DB::table('parametros')->whereNull('establecimiento_id')->orderBy('clave')->orderBy('especie_codigo')->orderBy('raza_codigo')->get()->map(fn ($p) => [
                'clave' => $p->clave, 'especie' => $p->especie_codigo, 'raza' => $p->raza_codigo, 'valor' => $j($p->valor),
                'unidad' => $p->unidad, 'fuente' => $p->fuente, 'validado_por_vet' => (bool) $p->validado_por_vet, 'vigente_desde' => $p->vigente_desde,
            ])->all(),
            'tipos_evento' => DB::table('tipos_evento')->join('tipos_evento_versiones as v', 'v.tipo_codigo', '=', 'tipos_evento.codigo')
                ->where('activo', true)->orderBy('orden')->orderBy('v.version')->get()->map(fn ($t) => [
                    'codigo' => $t->codigo, 'modulo' => $t->modulo, ...$conNombre($t), 'fase' => $t->fase,
                    'sujetos_admitidos' => PgTextArray::parse($t->sujetos_admitidos), 'crea_animales' => (bool) $t->crea_animales,
                    'requiere_sexo' => $t->requiere_sexo, 'version' => (int) $t->version, 'json_schema' => $j($t->json_schema),
                ])->all(),
            'tipos_documento' => DB::table('tipos_documento')->orderBy('orden')->get()->map(fn ($t) => [
                'codigo' => $t->codigo, ...$conNombre($t), 'privacidad_default' => $t->privacidad_default,
                'publicable' => (bool) $t->publicable, 'vence_por_defecto_dias' => $t->vence_por_defecto_dias, 'aviso' => $t->aviso,
            ])->all(),
            'tipos_prueba' => DB::table('tipos_prueba')->orderBy('id')->get()->map(fn ($t) => [
                'codigo' => $t->codigo, ...$conNombre($t), 'especies' => PgTextArray::parse($t->especies),
                'metodo_calculo' => $t->metodo_calculo, 'umbrales' => $j($t->umbrales), 'version' => (int) $t->version,
                'validado_por_vet' => (bool) $t->validado_por_vet,
            ])->all(),
            'items' => DB::table('catalogo_items')->whereNull('establecimiento_id')->where('activo', true)->orderBy('lista')->orderBy('orden')->get()
                ->groupBy('lista')->map(fn ($items) => $items->map(fn ($i) => [
                    'codigo' => $i->codigo, ...$conNombre($i), 'especies' => $i->especies === null ? null : PgTextArray::parse($i->especies),
                ])->values()->all())->all(),
            'plantillas_sanitarias' => DB::table('plantillas_sanitarias')->orderBy('id')->get()->map(fn ($p) => [
                'codigo' => $p->codigo, 'especie' => $p->especie_codigo, ...$conNombre($p), 'tipo_evento' => $p->tipo_evento,
                'enfermedad' => $p->enfermedad, 'alcance' => $j($p->alcance), 'disparador' => $j($p->disparador),
                'repeticion' => $j($p->repeticion), 'condicion' => $j($p->condicion), 'nota' => $p->nota, 'aviso' => $p->aviso,
                'fuente' => $p->fuente, 'version' => (int) $p->version, 'activa' => (bool) $p->activa,
            ])->all(),
            'geo' => [
                'provincias' => collect(Provincias::TODAS)->map(fn ($n, $c) => ['codigo' => (string) $c, 'nombre' => $n])->values()->all(),
                'departamentos' => DB::table('geo_departamentos')->orderBy('codigo')->get()->map(fn ($d) => [
                    'codigo' => $d->codigo, 'provincia_codigo' => $d->provincia_codigo, 'nombre' => $d->nombre,
                    'garrapata_sugerida' => $d->garrapata_sugerida,
                ])->all(),
            ],
        ];
    }
}
