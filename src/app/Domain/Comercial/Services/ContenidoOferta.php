<?php

namespace App\Domain\Comercial\Services;

use App\Domain\Catalogos\Parametros;
use App\Domain\Sanidad\Retiros;
use App\Http\Resources\V1\Hacienda\AnimalResource;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Datos **derivados** de la hacienda de una oferta (functional/05 §4): categorías, razas, peso promedio con fecha y
 * tipo de pesaje, rango, edad, dentición, clase de faena (Res. 470/2012, datos de `categorias_faena`), resumen
 * sanitario de los últimos 12 meses, retiro, origen y sistema. Sale de los animales elegidos o del lote (individuales
 * + conteo anónimo). Sin precios ni datos privados (el precio solo entra si `condiciones.mostrar_precio`).
 * Decimales como string (se hashea al publicar).
 */
final class ContenidoOferta
{
    /** Bloques opcionales que el productor elige mostrar (functional/05 §4). */
    public const BLOQUES = ['peso', 'edad', 'denticion', 'clase_faena', 'sanidad', 'origen', 'sistema', 'fotos', 'documentos'];

    /**
     * @param  list<string>  $animalIds  si viene vacío y hay lote: individuales activos del lote + conteo anónimo
     * @return array<string, mixed>
     */
    public static function precarga(string $establecimientoId, string $especie, ?string $loteId, array $animalIds): array
    {
        if ($animalIds === [] && $loteId) {
            $animalIds = DB::table('animales_estado')->where('lote_id', $loteId)->where('estado', 'activo')->pluck('animal_id')->all();
        }
        $animales = DB::table('animales_estado')->whereIn('animal_id', $animalIds)->where('estado', 'activo')->get();
        $conteo = ($loteId && DB::table('lotes')->where('id', $loteId)->value('anonimo'))
            ? DB::table('lote_conteos')->where('lote_id', $loteId)->where('cabezas', '>', 0)->pluck('cabezas', 'categoria_codigo')->map(fn ($n) => (int) $n)->all()
            : [];
        $loteEstado = $loteId ? DB::table('lotes_estado')->where('lote_id', $loteId)->first() : null;

        $categorias = [];
        foreach ($animales as $a) {
            $categorias[$a->categoria_codigo] = ($categorias[$a->categoria_codigo] ?? 0) + 1;
        }
        foreach ($conteo as $c => $n) {
            $categorias[$c] = ($categorias[$c] ?? 0) + $n;
        }
        $razas = [];
        foreach ($animales as $a) {
            if ($a->raza_codigo) {
                $razas[$a->raza_codigo] = ($razas[$a->raza_codigo] ?? 0) + 1;
            }
        }
        arsort($categorias);
        arsort($razas);

        // Peso: individuales (si hay) o el promedio del lote, lo más reciente.
        $pesados = $animales->whereNotNull('peso_kg');
        $peso = null;
        if ($pesados->isNotEmpty()) {
            $metodos = $pesados->countBy('peso_metodo')->sortDesc();
            $peso = ['promedio_kg' => self::dec($pesados->avg(fn ($a) => (float) $a->peso_kg), 1), 'min_kg' => self::dec($pesados->min(fn ($a) => (float) $a->peso_kg), 1),
                'max_kg' => self::dec($pesados->max(fn ($a) => (float) $a->peso_kg), 1), 'fecha' => $pesados->max('peso_fecha'),
                'metodo' => (string) $metodos->keys()->first(), 'declarado' => false];
        }
        if ($loteEstado?->peso_promedio_kg !== null && ($peso === null || $loteEstado->peso_promedio_fecha > $peso['fecha'])) {
            $peso = ['promedio_kg' => self::dec((float) $loteEstado->peso_promedio_kg, 1), 'min_kg' => null, 'max_kg' => null,
                'fecha' => $loteEstado->peso_promedio_fecha, 'metodo' => (string) $loteEstado->peso_promedio_metodo, 'declarado' => false];
        }

        $conFecha = $animales->whereNotNull('fecha_nacimiento')->map(fn ($a) => (int) floor(Carbon::parse($a->fecha_nacimiento)->diffInMonths(now(), true)));
        $edad = $conFecha->isEmpty() ? null : ['promedio_meses' => (int) round($conFecha->avg()), 'min_meses' => $conFecha->min(), 'max_meses' => $conFecha->max()];
        $dent = $animales->whereNotNull('denticion')->countBy('denticion')->sortDesc()->all();

        $clase = null;
        if ($peso !== null && $categorias !== []) {
            $clase = AnimalResource::claseFaena((object) [
                'especie_codigo' => $especie, 'categoria_codigo' => array_key_first($categorias),
                'denticion' => $dent === [] ? null : (string) array_key_first($dent), 'peso_kg' => $peso['promedio_kg'],
            ]);
        }

        $hoy = now()->toDateString();
        $origenes = $animales->countBy('origen');
        $total = $animales->count();

        return [
            'especie' => $especie,
            'cantidad' => array_sum($categorias),
            'categorias' => array_map(fn ($c, $n) => ['codigo' => $c, 'cantidad' => $n], array_keys($categorias), $categorias),
            'razas' => array_map(fn ($c, $n) => ['codigo' => $c, 'cantidad' => $n], array_keys($razas), $razas),
            'peso' => $peso,
            'edad' => $edad,
            'denticion' => $dent === [] ? null : $dent,
            'clase_faena' => $clase,
            'sanidad' => self::sanidad($establecimientoId, $animalIds, $loteId),
            'sin_retiro_vigente' => ($total === 0 && $conteo === []) ? null : ! ($animales->contains(fn ($a) => ($a->retiro_carne_hasta && $a->retiro_carne_hasta >= $hoy) || ($a->retiro_leche_hasta && $a->retiro_leche_hasta >= $hoy))
                || ($loteId !== null && Retiros::loteVigente($loteId, $hoy))),
            'origen' => $total === 0 ? null : [
                'nacidos_pct' => self::dec(100 * ($origenes['nacido'] ?? 0) / $total, 0),
                'comprados_pct' => self::dec(100 * ($origenes['comprado'] ?? 0) / $total, 0),
            ],
            'sistema' => self::sistema($establecimientoId),
            '_reactores' => $animales->where('reactor', true)->count(),
            '_animal_ids' => $animales->pluck('animal_id')->values()->all(),
        ];
    }

    /**
     * Contenido público = precarga filtrada por `mostrar` + condiciones + textos. Las claves `_privadas` nunca salen.
     *
     * @param  array<string, mixed>  $precarga
     * @param  array<string, mixed>  $mostrar
     * @param  array<string, mixed>  $condiciones
     * @return array<string, mixed>
     */
    public static function publico(array $precarga, string $tipo, int $cantidad, array $mostrar, array $condiciones, array $textos, array $fotos = []): array
    {
        $c = ['tipo' => $tipo, 'especie' => $precarga['especie'], 'cantidad' => $cantidad, 'categorias' => $precarga['categorias'], 'razas' => $precarga['razas']];
        foreach (['peso', 'edad', 'denticion', 'clase_faena', 'sanidad', 'origen', 'sistema'] as $b) {
            $c[$b] = ($mostrar[$b] ?? true) ? $precarga[$b] : null;
        }
        $c['sin_retiro_vigente'] = ($mostrar['sanidad'] ?? true) ? $precarga['sin_retiro_vigente'] : null;
        $c['condiciones'] = array_filter([
            'modalidad' => $condiciones['modalidad'] ?? null,
            'mostrar_precio' => (bool) ($condiciones['mostrar_precio'] ?? false),
            // el precio solo es público por acción explícita del productor
            'precio_kg' => ($condiciones['mostrar_precio'] ?? false) ? ($condiciones['precio_kg'] ?? null) : null,
            'moneda' => ($condiciones['mostrar_precio'] ?? false) ? ($condiciones['moneda'] ?? 'ARS') : null,
            'lugar_carga' => $condiciones['lugar_carga'] ?? null,
            'disponible_desde' => $condiciones['disponible_desde'] ?? null,
            'disponible_hasta' => $condiciones['disponible_hasta'] ?? null,
        ], fn ($v) => $v !== null);
        $c['documentos'] = [];
        // Fotos (bugfix TPA-54): hashes congelados en la publicación; la ficha pública los convierte en Foto.
        $c['fotos'] = ($mostrar['fotos'] ?? true) ? array_values($fotos) : [];
        $c['textos'] = (object) $textos;

        return $c;
    }

    /** @return list<array<string, mixed>> últimas aplicaciones sanitarias (12 meses) por enfermedad/tipo, sin datos individuales */
    private static function sanidad(string $est, array $animalIds, ?string $loteId): array
    {
        $q = DB::table('eventos_vigentes as v')->where('v.establecimiento_id', $est)->where('v.accion', '<>', 'anulacion')
            ->whereIn('v.tipo', ['sanidad.vacunacion', 'sanidad.desparasitacion'])->where('v.ocurrido_en', '>=', now()->subYear())
            ->where(function ($w) use ($animalIds, $loteId) {
                $w->whereExists(fn ($s) => $s->select(DB::raw(1))->from('evento_animales as ea')->whereColumn('ea.evento_id', 'v.id')->whereIn('ea.animal_id', $animalIds ?: ['00000000-0000-0000-0000-000000000000']));
                if ($loteId) {
                    $w->orWhere('v.lote_id', $loteId);
                }
            })->orderByDesc('v.ocurrido_en')->get(['v.tipo', 'v.payload', 'v.ocurrido_en']);
        $vistos = [];
        foreach ($q as $e) {
            $p = json_decode($e->payload, true);
            $clave = $e->tipo === 'sanidad.vacunacion' ? ($p['enfermedad'] ?? 'otra') : 'desparasitacion:'.($p['tipo'] ?? '');
            $vistos[$clave] ??= ['tipo' => $e->tipo === 'sanidad.vacunacion' ? 'vacunacion' : 'desparasitacion',
                'enfermedad' => $p['enfermedad'] ?? null, 'producto' => $p['producto_nombre'] ?? null,
                'fecha' => Carbon::parse($e->ocurrido_en)->toDateString()];
        }

        return array_values($vistos);
    }

    private static function sistema(string $est): ?string
    {
        $e = DB::table('establecimientos')->where('id', $est)->first(['actividades']);
        $ambientes = DB::table('potreros')->where('establecimiento_id', $est)->whereNull('archivado_en')->whereNotNull('ambiente')->distinct()->pluck('ambiente')->all();
        $acts = trim((string) $e?->actividades, '{}');

        return trim('pastoril'.($ambientes ? ' · ambientes: '.implode(', ', $ambientes) : '').($acts ? ' · '.str_replace(',', ', ', $acts) : ''));
    }

    private static function dec(float $v, int $decimales): string
    {
        return (string) Formato::decimal(number_format($v, $decimales, '.', ''));
    }

    /**
     * Avisos previos a publicar (functional/05 §4): retiro vigente, reactores, pesaje > `pesaje_antiguo_dias`,
     * documentos vencidos (cuando exista la carpeta documental, TPA-36).
     *
     * @param  array<string, mixed>  $precarga
     * @return list<array{codigo: string, mensaje: string}>
     */
    public static function avisos(array $precarga, string $establecimientoId): array
    {
        $avisos = [];
        if ($precarga['sin_retiro_vigente'] === false) {
            $avisos[] = ['codigo' => 'retiro_vigente', 'mensaje' => 'Hay animales con período de retiro vigente.'];
        }
        if (($precarga['_reactores'] ?? 0) > 0) {
            $avisos[] = ['codigo' => 'reactores', 'mensaje' => 'Hay animales reactores en la selección (dato privado: no se publica).'];
        }
        $dias = (int) (Parametros::valor('pesaje_antiguo_dias', $precarga['especie'], null, $establecimientoId) ?? 60);
        if ($precarga['peso'] === null) {
            $avisos[] = ['codigo' => 'sin_pesaje', 'mensaje' => 'No hay pesajes registrados.'];
        } elseif (Carbon::parse($precarga['peso']['fecha'])->lt(now()->subDays($dias))) {
            $avisos[] = ['codigo' => 'pesaje_antiguo', 'mensaje' => "El último pesaje tiene más de {$dias} días."];
        }

        return $avisos;
    }
}
