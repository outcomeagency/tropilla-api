<?php

namespace App\Domain\Telegram\Asistente;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Clima\Actions\ActualizarClima;
use App\Domain\Documentos\ConsultasDocumentos;
use App\Domain\Hacienda\ConsultasAnimales;
use App\Domain\Hacienda\Proyecciones\Identificaciones;
use App\Domain\Reproduccion\TableroReproductivo;
use App\Domain\Telegram\Contexto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Herramientas **de solo lectura** del asistente (function calling, TPA-77). Cada una exige la misma ability que el
 * endpoint equivalente de la API y reusa sus consultas de dominio (existencias, `ConsultasAnimales`, `TableroReproductivo`,
 * `ConsultasDocumentos`, tareas del plan sanitario, `ActualizarClima`, ofertas). Devuelven datos compactos; el modelo
 * redacta la respuesta y no puede inventar lo que no está acá. Nunca devuelven precios ni datos fiscales.
 */
final class Herramientas
{
    /** nombre => [ability, descripción, parámetros (Schema de Gemini)] */
    private const DEFINICIONES = [
        'existencias' => ['hacienda.ver', 'Existencias actuales del establecimiento: cabezas por especie y categoría (animales con caravana + cabezas de lotes sin caravanear).', null],
        'buscar_animal' => ['hacienda.ver', 'Busca un animal por caravana, RFID, tatuaje u otra identificación y devuelve su ficha resumida (categoría, lote, potrero, último peso, estado reproductivo, fecha probable de parto).',
            ['type' => 'OBJECT', 'properties' => ['identificacion' => ['type' => 'STRING', 'description' => 'Número o texto de la caravana / identificación tal como lo dijo el usuario']], 'required' => ['identificacion']]],
        'reproduccion' => ['reproduccion.ver', 'Tablero reproductivo de los vientres: cuántas vacas/búfalas están vacías, servidas, preñadas o paridas, preñadas por mes de parto y partos próximos o vencidos.',
            ['type' => 'OBJECT', 'properties' => ['especie' => ['type' => 'STRING', 'enum' => ['BOV', 'BUB'], 'description' => 'BOV bovinos, BUB búfalos. Omitir para todas']]]],
        'vencimientos' => [null, 'Lo que vence en los próximos días: tareas sanitarias pendientes o vencidas del plan, documentos que vencen y partos probables.',
            ['type' => 'OBJECT', 'properties' => ['dias' => ['type' => 'INTEGER', 'description' => 'Ventana en días desde hoy (por defecto 30, máximo 120). Para «este mes» usar los días que faltan hasta fin de mes']]]],
        'alertas_clima' => ['establecimiento.ver', 'Alertas climáticas activas para la zona del establecimiento (lluvia, sequía, helada, calor, incendio) y el pronóstico de los próximos días.', null],
        'ofertas' => ['ofertas.ver', 'Ofertas de venta de hacienda del establecimiento (estado, tipo, cantidad, categorías). No incluye precios.',
            ['type' => 'OBJECT', 'properties' => ['estado' => ['type' => 'STRING', 'enum' => ['borrador', 'publicada', 'reservada', 'vendida', 'retirada']]]]],
    ];

    /** @return list<array<string, mixed>> functionDeclarations que el usuario puede usar en el establecimiento activo */
    public static function declaraciones(Contexto $ctx): array
    {
        $out = [];
        foreach (self::DEFINICIONES as $nombre => [$ability, $desc, $params]) {
            if (self::permitida($ctx, $nombre, $ability)) {
                $out[] = array_filter(['name' => $nombre, 'description' => $desc, 'parameters' => $params]);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    public static function ejecutar(Contexto $ctx, string $nombre, array $args): array
    {
        if (! isset(self::DEFINICIONES[$nombre]) || ! self::permitida($ctx, $nombre, self::DEFINICIONES[$nombre][0])) {
            return ['error' => 'Herramienta no disponible para el rol del usuario en este establecimiento.'];
        }
        try {
            return match ($nombre) {
                'existencias' => self::existencias($ctx),
                'buscar_animal' => self::buscarAnimal($ctx, (string) ($args['identificacion'] ?? '')),
                'reproduccion' => self::reproduccion($ctx, isset($args['especie']) && in_array($args['especie'], ['BOV', 'BUB'], true) ? $args['especie'] : null),
                'vencimientos' => self::vencimientos($ctx, (int) ($args['dias'] ?? 30)),
                'alertas_clima' => self::clima($ctx),
                'ofertas' => self::ofertas($ctx, is_string($args['estado'] ?? null) ? $args['estado'] : null),
            };
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'No se pudo consultar ese dato ahora.'];
        }
    }

    private static function permitida(Contexto $ctx, string $nombre, ?string $ability): bool
    {
        if ($nombre === 'vencimientos') {
            return $ctx->puede('sanidad.ver') || $ctx->puede('documentos.ver') || $ctx->puede('reproduccion.ver');
        }

        return $ability !== null && $ctx->puede($ability);
    }

    /** @return array<string, mixed> */
    private static function existencias(Contexto $ctx): array
    {
        $filas = DB::table('existencias as x')->join('categorias as c', 'c.codigo', '=', 'x.categoria_codigo')
            ->where('x.establecimiento_id', $ctx->est->id)->orderBy('x.especie_codigo')->orderBy('c.orden')
            ->get(['x.especie_codigo', 'x.categoria_codigo', 'c.nombres', 'x.cabezas', 'x.individuales', 'x.anonimas']);

        return [
            'establecimiento' => $ctx->est->nombre,
            'total_cabezas' => (int) $filas->sum('cabezas'),
            'por_categoria' => $filas->map(fn ($f) => [
                'especie' => $f->especie_codigo === 'BUB' ? 'búfalos' : 'bovinos', 'categoria' => self::nombre($f->nombres, $f->categoria_codigo),
                'cabezas' => (int) $f->cabezas, 'con_caravana' => (int) $f->individuales, 'sin_caravanear' => (int) $f->anonimas,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function buscarAnimal(Contexto $ctx, string $identificacion): array
    {
        $valor = Identificaciones::normalizar(mb_substr($identificacion, 0, 64));
        if ($valor === '') {
            return ['encontrados' => 0, 'animales' => []];
        }
        $ids = DB::table('animal_identificaciones')->where('establecimiento_id', $ctx->est->id)
            ->whereRaw(Identificaciones::SQL_SIN_CEROS.' = ?', [Identificaciones::sinCeros($valor)])->where('vigente', true)
            ->distinct()->limit(5)->pluck('animal_id')->all();
        $verSanidad = $ctx->puede('sanidad.ver');
        $verRepro = $ctx->puede('reproduccion.ver');
        $animales = ConsultasAnimales::base($ctx->est->id)->whereIn('a.animal_id', $ids)->get()->map(fn ($a) => array_filter([
            'identificacion' => $a->identificacion_principal ?? null,
            'especie' => $a->especie_codigo === 'BUB' ? 'búfalo' : 'bovino',
            'sexo' => $a->sexo === 'H' ? 'hembra' : 'macho',
            'categoria' => self::nombre($a->categoria_nombres, $a->categoria_codigo),
            'estado' => $a->estado,
            'baja_en' => $a->baja_en,
            'lote' => $a->lote_nombre,
            'potrero' => $a->potrero_nombre,
            'raza' => $a->raza_codigo,
            'fecha_nacimiento' => $a->fecha_nacimiento,
            'ultimo_peso_kg' => $a->peso_kg !== null ? (float) $a->peso_kg : null,
            'ultimo_peso_fecha' => $a->peso_fecha,
            'estado_reproductivo' => $verRepro ? $a->estado_reproductivo : null,
            'fecha_probable_parto' => $verRepro ? ($a->fecha_probable_parto ?? null) : null,
            'retiro_carne_hasta' => $verSanidad ? ($a->retiro_carne_hasta ?? null) : null,
        ], fn ($v) => $v !== null))->values()->all();

        return ['encontrados' => count($animales), 'animales' => $animales];
    }

    /** @return array<string, mixed> */
    private static function reproduccion(Contexto $ctx, ?string $especie): array
    {
        $t = app(TableroReproductivo::class)->tablero($ctx->est, $especie);

        return ['tablero' => array_map(fn ($e) => $e + ['vientres' => $e['especie'] === 'BUB' ? 'búfalas' : 'vacas'], $t),
            'nota' => 'Cuenta vientres activos según los eventos cargados (servicios, tactos, partos). Las que no tienen tacto cargado figuran sin estado.'];
    }

    /** @return array<string, mixed> */
    private static function vencimientos(Contexto $ctx, int $dias): array
    {
        $dias = max(1, min(120, $dias));
        $hoy = $ctx->hoy();
        $hasta = Carbon::parse($hoy)->addDays($dias)->toDateString();
        $out = ['desde' => $hoy, 'hasta' => $hasta];
        if ($ctx->puede('sanidad.ver')) {
            $out['tareas_sanitarias'] = DB::table('tareas')->where('establecimiento_id', $ctx->est->id)->where('estado', 'pendiente')->where('vence_en', '<=', $hasta)
                ->orderBy('vence_en')->limit(20)->get(['titulo', 'vence_en', 'cabezas_estimadas'])
                ->map(fn ($t) => ['titulo' => $t->titulo, 'vence_en' => $t->vence_en, 'vencida' => $t->vence_en < $hoy, 'cabezas_estimadas' => $t->cabezas_estimadas])->all();
        }
        if ($ctx->puede('documentos.ver')) {
            $out['documentos'] = ConsultasDocumentos::base($ctx->est->id, $ctx->puede('documentos.fiscal'))
                ->where('d.estado', 'vigente')->whereNotNull('d.vence_en')->where('d.vence_en', '<=', $hasta)
                ->orderBy('d.vence_en')->limit(20)->get(['d.titulo', 'd.tipo_codigo', 'd.vence_en'])
                ->map(fn ($d) => ['titulo' => $d->titulo, 'vence_en' => $d->vence_en, 'vencido' => $d->vence_en < $hoy])->all();
        }
        if ($ctx->puede('reproduccion.ver')) {
            $partos = app(TableroReproductivo::class)->partos($ctx->est, ['hasta' => $hasta]);
            $out['partos_probables'] = ['cantidad' => count($partos), 'primeros' => array_map(fn ($p) => [
                'identificacion' => $p['fila']->identificacion_principal ?? null, 'fecha_probable_parto' => $p['fecha_probable_parto'], 'vencido' => $p['vencido'],
            ], array_slice($partos, 0, 10))];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function clima(Contexto $ctx): array
    {
        $est = $ctx->est;
        $estado = DB::table('clima_estado')->where('establecimiento_id', $est->id)->first();
        if ($estado === null || Carbon::parse($estado->calculado_en)->lt(now()->subHours((int) config('tropilla.clima.vigencia_horas')))) {
            app(ActualizarClima::class)->handle($est);
            $estado = DB::table('clima_estado')->where('establecimiento_id', $est->id)->first();
        }
        if ($estado === null) {
            return ['error' => 'Sin datos de clima para este establecimiento (falta la ubicación o la zona).'];
        }
        $hoy = $ctx->hoy();
        $alertas = DB::table('clima_alertas')->where('establecimiento_id', $est->id)->where('estado', 'activa')->where('hasta', '>=', $hoy)
            ->orderByRaw("CASE severidad WHEN 'alta' THEN 0 WHEN 'moderada' THEN 1 ELSE 2 END")->orderBy('desde')->limit(10)
            ->get(['titulo', 'severidad', 'detalle', 'recomendacion', 'desde', 'hasta', 'fuente']);

        return [
            'zona' => $estado->zona,
            'calculado_en' => $estado->calculado_en,
            'alertas' => $alertas->map(fn ($a) => (array) $a)->all(),
            'pronostico' => array_slice(array_values(array_filter(json_decode((string) $estado->pronostico, true) ?? [], fn ($d) => ($d['fecha'] ?? '') >= $hoy)), 0, 5),
            'aviso' => 'Basado en modelos y reanálisis para la zona, no en mediciones del campo.',
        ];
    }

    /** @return array<string, mixed> */
    private static function ofertas(Contexto $ctx, ?string $estado): array
    {
        $q = DB::table('ofertas')->where('establecimiento_id', $ctx->est->id)
            ->when($estado, fn ($q, $e) => $q->where('estado', $e), fn ($q) => $q->whereIn('estado', ['borrador', 'publicada', 'reservada']))
            ->orderByDesc('created_at')->limit(15)->get(['tipo', 'especie_codigo', 'cantidad', 'estado', 'categorias', 'publicada_en', 'created_at']);

        return ['ofertas' => $q->map(fn ($o) => [
            'tipo' => $o->tipo, 'especie' => $o->especie_codigo === 'BUB' ? 'búfalos' : 'bovinos', 'cabezas' => (int) $o->cantidad, 'estado' => $o->estado,
            'categorias' => trim((string) $o->categorias, '{}') === '' ? [] : explode(',', trim((string) $o->categorias, '{}')),
            'publicada_en' => $o->publicada_en, 'creada_en' => substr((string) $o->created_at, 0, 10),
        ])->all()];
    }

    private static function nombre(?string $nombres, string $codigo): string
    {
        return (string) (Catalogos::nombre($nombres, 'es') ?: $codigo);
    }
}
