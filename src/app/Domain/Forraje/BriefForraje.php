<?php

namespace App\Domain\Forraje;

use App\Domain\Catalogos\Parametros;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Brief preventivo de forraje y carga (TPA-71). Cruza el inventario declarado (existencias), la superficie y la receptividad que
 * declaró el productor en la ficha (TPA-76), las reservas que carga en el brief y el contexto climático ya calculado (TPA-70),
 * y arma escenarios de oferta de pasto con supuestos explícitos y un rango de incertidumbre.
 *
 * No receta manejo: no calcula "carga óptima", raciones ni órdenes. Expone diferencias entre lo declarado y cada escenario y
 * preguntas para conversar con el asesor. Los coeficientes son parámetros (datos), ajustables por establecimiento o por brief.
 */
final class BriefForraje
{
    public const AVISO = 'Es un escenario exploratorio armado con lo que declaraste y con coeficientes orientativos, no una recomendación de manejo. '
        .'Los resultados pueden variar mucho con el estado real de las pasturas y de los animales: revisalos con tu asesor antes de decidir.';

    public const ESCENARIOS = ['base' => 'Oferta de pasto como la receptividad declarada', 'moderado' => 'Oferta de pasto reducida (moderado)', 'severo' => 'Oferta de pasto muy reducida (severo)'];

    public const RESERVAS = ['rollos' => 'Rollos', 'silo' => 'Silo', 'suplemento' => 'Suplemento / grano'];

    /**
     * Datos de partida: lo que el brief usaría si no se cambia nada.
     *
     * @return array<string, mixed>
     */
    public function insumos(Establecimiento $est): array
    {
        $inventario = $this->inventarioDeclarado($est);

        return [
            'inventario' => $inventario['filas'],
            'inventario_actualizado_en' => $inventario['actualizado_en'],
            'superficie' => $this->superficie($est),
            'receptividad' => $this->receptividad($est),
            'supuestos' => $this->supuestos($est),
            'clima' => $this->clima($est),
            'reservas_ultimo_brief' => $this->reservasUltimoBrief($est),
        ];
    }

    /**
     * @param  array<string, mixed>  $entradas  BriefForrajeInput validado
     * @return array{entradas: array<string, mixed>, insumos: array<string, mixed>, resultado: array<string, mixed>}
     */
    public function calcular(Establecimiento $est, array $entradas): array
    {
        $base = $this->insumos($est);
        $sup = $base['supuestos'];
        $avisos = [];

        // Supuestos: el brief puede pisar cualquiera; queda registrado el origen de cada valor.
        $usados = [];
        foreach (['consumo_ms_kg_ev_dia', 'aprovechamiento_reservas_pct', 'incertidumbre_pct', 'reduccion_moderado_pct', 'reduccion_severo_pct', 'rollo_kg', 'ms_rollos_pct', 'ms_silo_pct', 'ms_suplemento_pct'] as $k) {
            $propio = $entradas['supuestos'][$k] ?? null;
            $usados[$k] = $propio !== null
                ? ['valor' => (float) $propio, 'origen' => 'brief', 'fuente' => 'Cargado en este brief', 'validado' => false]
                : ['valor' => (float) $sup[$k]['valor'], 'origen' => $sup[$k]['origen'], 'fuente' => $sup[$k]['fuente'], 'validado' => $sup[$k]['validado']];
        }
        $v = fn (string $k): float => $usados[$k]['valor'];

        // Inventario: el declarado, o el que se carga para explorar (p. ej. "si vendo los terneros").
        $inventario = $base['inventario'];
        $inventarioOrigen = 'existencias';
        if (isset($entradas['inventario'])) {
            $inventarioOrigen = 'brief';
            $inventario = [];
            foreach ($entradas['inventario'] as $fila) {
                $cat = DB::table('categorias')->where('codigo', $fila['categoria'])->first(['codigo', 'especie_codigo', 'nombres']);
                $inventario[] = $this->filaInventario($est, $cat->especie_codigo, $cat->codigo, json_decode($cat->nombres, true)['es'] ?? $cat->codigo, (int) $fila['cabezas']);
            }
        }
        foreach ($inventario as $f) {
            if ($f['ev_origen'] === 'sin_coeficiente') {
                $avisos[] = "No hay equivalencia EV para {$f['categoria_nombre']}: se usó 1 EV por cabeza.";
            }
        }
        $cabezas = array_sum(array_column($inventario, 'cabezas'));
        $ev = round(array_sum(array_map(fn ($f) => (float) $f['ev'], $inventario)), 2);

        $superficie = isset($entradas['superficie_ha'])
            ? ['valor' => (float) $entradas['superficie_ha'], 'origen' => 'brief', 'detalle' => 'Cargada en este brief']
            : ['valor' => $base['superficie']['valor'] === null ? null : (float) $base['superficie']['valor'], 'origen' => $base['superficie']['origen'], 'detalle' => $base['superficie']['detalle']];
        $recept = isset($entradas['receptividad_ev_ha'])
            ? ['valor' => (float) $entradas['receptividad_ev_ha'], 'origen' => 'brief', 'periodo' => null, 'criterio' => 'Cargada en este brief']
            : ['valor' => $base['receptividad']['valor'] === null ? null : (float) $base['receptividad']['valor'], 'origen' => $base['receptividad']['origen'],
                'periodo' => $base['receptividad']['periodo'], 'criterio' => $base['receptividad']['criterio']];
        $horizonte = (int) ($entradas['horizonte_dias'] ?? 90);

        // Reservas: kg de materia seca utilizable = cantidad × % MS × % aprovechamiento.
        $r = $entradas['reservas'] ?? [];
        $rollosKg = isset($r['rollos']['cantidad']) ? (float) $r['rollos']['cantidad'] * (float) ($r['rollos']['kg_por_rollo'] ?? $v('rollo_kg')) : 0.0;
        $siloKg = isset($r['silo']['toneladas']) ? (float) $r['silo']['toneladas'] * 1000 : 0.0;
        $suplKg = isset($r['suplemento']['kg']) ? (float) $r['suplemento']['kg'] : 0.0;
        $aprov = $v('aprovechamiento_reservas_pct') / 100;
        $reservas = [];
        foreach (['rollos' => [$rollosKg, $v('ms_rollos_pct')], 'silo' => [$siloKg, $v('ms_silo_pct')], 'suplemento' => [$suplKg, $v('ms_suplemento_pct')]] as $tipo => [$kg, $ms]) {
            if ($kg > 0) {
                $reservas[] = ['tipo' => $tipo, 'nombre' => self::RESERVAS[$tipo], 'kg_tal_cual' => self::d($kg, 0), 'ms_pct' => self::d($ms, 1),
                    'ms_utilizable_kg' => self::d($kg * $ms / 100 * $aprov, 0)];
            }
        }
        $msReservas = array_sum(array_map(fn ($x) => (float) $x['ms_utilizable_kg'], $reservas));

        $consumo = $v('consumo_ms_kg_ev_dia');
        $inc = $v('incertidumbre_pct') / 100;
        $demandaDia = $ev * $consumo;
        $cobertura = $demandaDia > 0 && $msReservas > 0 ? [
            'dias' => (int) floor($msReservas / $demandaDia),
            'dias_min' => (int) floor($msReservas / ($demandaDia * (1 + $inc))),
            'dias_max' => (int) floor($msReservas / ($demandaDia * max(0.01, 1 - $inc))),
        ] : null;

        $faltantes = [];
        if ($cabezas === 0) {
            $faltantes[] = ['campo' => 'inventario', 'mensaje' => 'No hay existencias declaradas: cargá la hacienda o un inventario para el escenario.'];
        }
        if ($superficie['valor'] === null || $superficie['valor'] <= 0) {
            $faltantes[] = ['campo' => 'superficie_ha', 'mensaje' => 'Falta la superficie ganadera: cargala en el establecimiento o en el brief.'];
        }
        if ($recept['valor'] === null || $recept['valor'] <= 0) {
            $faltantes[] = ['campo' => 'receptividad_ev_ha', 'mensaje' => 'Falta la receptividad (EV/ha): declarala en la ficha (Capacidad de carga) o en el brief.'];
        }
        if ($reservas === []) {
            $faltantes[] = ['campo' => 'reservas', 'mensaje' => 'No cargaste reservas: los escenarios suponen que no hay rollos, silo ni suplemento.'];
        }

        $puedeEscenario = $superficie['valor'] > 0 && $recept['valor'] > 0 && $cabezas > 0;
        $reduccion = ['base' => 0.0, 'moderado' => $v('reduccion_moderado_pct'), 'severo' => $v('reduccion_severo_pct')];
        $escenarios = [];
        foreach (self::ESCENARIOS as $codigo => $nombre) {
            $escenarios[] = $puedeEscenario
                ? $this->escenario($codigo, $nombre, $reduccion[$codigo], $ev, (float) $superficie['valor'], (float) $recept['valor'], $consumo, $inc, $msReservas, $horizonte)
                : ['codigo' => $codigo, 'nombre' => $nombre, 'reduccion_oferta_pct' => self::d($reduccion[$codigo], 1), 'estado' => 'sin_datos',
                    'lectura' => 'Faltan datos para calcular este escenario.'];
        }

        $clima = $base['clima'];
        $cercano = $this->escenarioCercano($clima);

        $resultado = [
            'aviso' => self::AVISO,
            'piloto' => true,
            'horizonte_dias' => $horizonte,
            'inventario' => ['origen' => $inventarioOrigen, 'cabezas' => $cabezas, 'ev' => self::d($ev, 2), 'filas' => $inventario,
                'actualizado_en' => $inventarioOrigen === 'existencias' ? $base['inventario_actualizado_en'] : null],
            'superficie' => ['valor_ha' => self::d($superficie['valor'], 2), 'origen' => $superficie['origen'], 'detalle' => $superficie['detalle']],
            'receptividad' => ['valor_ev_ha' => self::d($recept['valor'], 2), 'origen' => $recept['origen'], 'periodo' => $recept['periodo'], 'criterio' => $recept['criterio']],
            'carga_declarada_ev_ha' => $superficie['valor'] > 0 ? self::d($ev / $superficie['valor'], 2) : null,
            'reservas' => ['items' => $reservas, 'ms_utilizable_kg' => self::d($msReservas, 0), 'cobertura_rodeo_completo' => $cobertura],
            'escenarios' => $escenarios,
            'escenario_cercano_al_clima' => $cercano,
            'supuestos' => array_map(fn ($k) => ['clave' => $k, 'nombre' => self::NOMBRES_SUPUESTOS[$k][0], 'unidad' => self::NOMBRES_SUPUESTOS[$k][1],
                'valor' => self::d($usados[$k]['valor'], 2), 'origen' => $usados[$k]['origen'], 'fuente' => $usados[$k]['fuente'], 'validado' => $usados[$k]['validado']], array_keys($usados)),
            'incertidumbre' => [
                'margen_pct' => self::d($v('incertidumbre_pct'), 1),
                'explicacion' => 'Los días de cobertura se muestran como rango aplicando ±'.self::n($v('incertidumbre_pct')).' % a la demanda diaria. '
                    .'Es un margen de prudencia, no un intervalo estadístico: no contempla crecimiento real del pasto, calidad del forraje, preñez/lactancia ni pérdidas extraordinarias.',
                'no_contempla' => ['Crecimiento y calidad real del pasto en este período', 'Estado corporal, preñez y lactancia de cada categoría', 'Disponibilidad y calidad de agua', 'Pérdidas por anegamiento, fuego o robo de reservas', 'Precios y logística de reservas adicionales'],
            ],
            'clima' => $clima,
            'faltantes' => $faltantes,
            'avisos' => $avisos,
            'preguntas_asesor' => $this->preguntas($inventario, $recept, $escenarios, $reservas, $clima, $usados),
        ];

        return [
            'entradas' => $entradas,
            'insumos' => ['supuestos_vigentes' => $sup, 'superficie' => $base['superficie'], 'receptividad' => $base['receptividad']],
            'resultado' => $resultado,
        ];
    }

    private const NOMBRES_SUPUESTOS = [
        'consumo_ms_kg_ev_dia' => ['Consumo de materia seca por EV y día', 'kg MS/EV/día'],
        'aprovechamiento_reservas_pct' => ['Aprovechamiento de reservas (descuenta pérdidas)', '%'],
        'incertidumbre_pct' => ['Margen de incertidumbre aplicado a la demanda', '%'],
        'reduccion_moderado_pct' => ['Menor oferta de pasto en el escenario moderado', '%'],
        'reduccion_severo_pct' => ['Menor oferta de pasto en el escenario severo', '%'],
        'rollo_kg' => ['Peso de un rollo (tal cual)', 'kg'],
        'ms_rollos_pct' => ['Materia seca de los rollos', '%'],
        'ms_silo_pct' => ['Materia seca del silo', '%'],
        'ms_suplemento_pct' => ['Materia seca del suplemento', '%'],
    ];

    /** @return array<string, mixed> */
    private function escenario(string $codigo, string $nombre, float $reduccionPct, float $ev, float $sup, float $recept, float $consumo, float $inc, float $msReservas, int $horizonte): array
    {
        $sostenible = $recept * (1 - $reduccionPct / 100) * $sup;
        $diferencia = $ev - $sostenible;
        $faltanteDia = max(0.0, $diferencia) * $consumo;
        $faltanteHorizonte = $faltanteDia * $horizonte;
        $dias = $faltanteDia > 0 && $msReservas > 0 ? [
            'dias' => (int) floor($msReservas / $faltanteDia),
            'dias_min' => (int) floor($msReservas / ($faltanteDia * (1 + $inc))),
            'dias_max' => (int) floor($msReservas / ($faltanteDia * max(0.01, 1 - $inc))),
        ] : null;

        if ($faltanteDia <= 0) {
            $estado = 'sin_faltante';
            $lectura = 'Con estos supuestos, la carga declarada no supera la oferta de pasto del escenario ('.self::n($sostenible, 0).' EV de referencia).';
        } elseif ($msReservas <= 0) {
            $estado = 'sin_reservas';
            $lectura = 'Con estos supuestos faltarían unos '.self::n($faltanteDia / 1000, 1).' t de MS por día y no hay reservas cargadas.';
        } elseif ($dias['dias_min'] >= $horizonte) {
            $estado = 'cubierto';
            $lectura = "Las reservas cargadas cubrirían el faltante del escenario durante el horizonte de {$horizonte} días, incluso en el extremo desfavorable del rango.";
        } else {
            $estado = 'parcial';
            $lectura = "Las reservas cargadas cubrirían el faltante del escenario entre {$dias['dias_min']} y {$dias['dias_max']} días, menos que el horizonte de {$horizonte} días.";
        }

        return [
            'codigo' => $codigo, 'nombre' => $nombre, 'reduccion_oferta_pct' => self::d($reduccionPct, 1), 'estado' => $estado, 'lectura' => $lectura,
            'ev_referencia' => self::d($sostenible, 1),
            'diferencia_ev' => self::d($diferencia, 1),
            'faltante_ms_kg_dia' => self::d($faltanteDia, 0),
            'faltante_ms_kg_horizonte' => self::d($faltanteHorizonte, 0),
            'balance_reservas_kg' => self::d($msReservas - $faltanteHorizonte, 0),
            'cobertura' => $dias,
        ];
    }

    /** @return array{filas: list<array<string, mixed>>, actualizado_en: ?string} */
    private function inventarioDeclarado(Establecimiento $est): array
    {
        $rows = DB::table('existencias as e')->join('categorias as c', 'c.codigo', '=', 'e.categoria_codigo')
            ->where('e.establecimiento_id', $est->id)->where('e.cabezas', '>', 0)
            ->orderBy('e.especie_codigo')->orderBy('c.orden')
            ->get(['e.especie_codigo', 'e.categoria_codigo', 'e.cabezas', 'e.actualizado_en', 'c.nombres']);
        $max = $rows->max('actualizado_en');

        return [
            'filas' => $rows->map(fn ($r) => $this->filaInventario($est, $r->especie_codigo, $r->categoria_codigo, json_decode($r->nombres, true)['es'] ?? $r->categoria_codigo, (int) $r->cabezas))->values()->all(),
            'actualizado_en' => $max ? Formato::fechaHora(Carbon::parse($max)) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function filaInventario(Establecimiento $est, string $especie, string $categoria, string $nombre, int $cabezas): array
    {
        $tabla = Parametros::resolver('forraje_ev_categoria', $especie, null, $est->id);
        $coef = is_array($tabla['valor'] ?? null) ? ($tabla['valor'][$categoria] ?? null) : null;

        return [
            'especie' => $especie, 'categoria' => $categoria, 'categoria_nombre' => $nombre, 'cabezas' => $cabezas,
            'ev_por_cabeza' => self::d($coef ?? 1.0, 2),
            'ev' => self::d($cabezas * (float) ($coef ?? 1.0), 2),
            'ev_origen' => $coef === null ? 'sin_coeficiente' : ($tabla['origen'] === 'establecimiento' ? 'establecimiento' : 'parametro'),
        ];
    }

    /** @return array{valor: ?string, origen: string, detalle: string} */
    private function superficie(Establecimiento $est): array
    {
        if ((float) $est->superficie_ha > 0) {
            return ['valor' => self::d((float) $est->superficie_ha, 2), 'origen' => 'establecimiento', 'detalle' => 'Superficie declarada del establecimiento'];
        }
        $potreros = (float) DB::table('potreros')->where('establecimiento_id', $est->id)->whereNull('archivado_en')->sum('superficie_ha');
        if ($potreros > 0) {
            return ['valor' => self::d($potreros, 2), 'origen' => 'potreros', 'detalle' => 'Suma de los potreros activos con superficie cargada'];
        }

        return ['valor' => null, 'origen' => 'sin_dato', 'detalle' => 'Sin superficie declarada'];
    }

    /** @return array{valor: ?string, origen: string, periodo: ?string, criterio: ?string} */
    private function receptividad(Establecimiento $est): array
    {
        $bloque = ($est->ficha_ampliada ?? [])['capacidad_carga'] ?? [];
        $valor = is_numeric($bloque['carga_ug_ha'] ?? null) ? (float) $bloque['carga_ug_ha'] : null;

        return $valor !== null && $valor > 0
            ? ['valor' => self::d($valor, 2), 'origen' => 'ficha', 'periodo' => is_string($bloque['periodo'] ?? null) ? $bloque['periodo'] : null,
                'criterio' => is_string($bloque['criterio'] ?? null) ? $bloque['criterio'] : 'Capacidad de carga declarada en la ficha (UG ≈ EV)']
            : ['valor' => null, 'origen' => 'sin_dato', 'periodo' => null, 'criterio' => null];
    }

    /** @return array<string, array{valor: string, origen: string, fuente: string, validado: bool}> */
    private function supuestos(Establecimiento $est): array
    {
        $p = fn (string $clave) => Parametros::resolver($clave, null, null, $est->id);
        $fmt = fn (?array $r, mixed $valor) => ['valor' => self::d((float) $valor, 2), 'origen' => ($r['origen'] ?? 'global') === 'establecimiento' ? 'establecimiento' : 'parametro',
            'fuente' => $r['fuente'] ?? 'TROPILLA', 'validado' => (bool) ($r['validado_por_vet'] ?? false)];
        $red = $p('forraje_reduccion_oferta_pct');
        $ms = $p('forraje_ms_pct');

        return [
            'consumo_ms_kg_ev_dia' => $fmt($c = $p('forraje_consumo_ms_kg_ev_dia'), $c['valor'] ?? 10),
            'aprovechamiento_reservas_pct' => $fmt($a = $p('forraje_aprovechamiento_reservas_pct'), $a['valor'] ?? 80),
            'incertidumbre_pct' => $fmt($i = $p('forraje_incertidumbre_pct'), $i['valor'] ?? 20),
            'reduccion_moderado_pct' => $fmt($red, $red['valor']['moderado'] ?? 25),
            'reduccion_severo_pct' => $fmt($red, $red['valor']['severo'] ?? 50),
            'rollo_kg' => $fmt($k = $p('forraje_rollo_kg'), $k['valor'] ?? 450),
            'ms_rollos_pct' => $fmt($ms, $ms['valor']['rollos'] ?? 85),
            'ms_silo_pct' => $fmt($ms, $ms['valor']['silo'] ?? 35),
            'ms_suplemento_pct' => $fmt($ms, $ms['valor']['suplemento'] ?? 88),
        ];
    }

    /** Contexto climático ya calculado por TPA-70 (no dispara consultas a fuentes). @return array<string, mixed> */
    private function clima(Establecimiento $est): array
    {
        $estado = DB::table('clima_estado')->where('establecimiento_id', $est->id)->first();
        if ($estado === null) {
            return ['disponible' => false, 'mensaje' => 'Todavía no hay alertas climáticas calculadas para este establecimiento. Abrí Clima para calcularlas.'];
        }
        $hoy = Carbon::now($est->zona_horaria)->toDateString();
        $alertas = DB::table('clima_alertas')->where('establecimiento_id', $est->id)->where('estado', 'activa')->where('hasta', '>=', $hoy)
            ->whereIn('tipo', ['sequia', 'lluvia', 'helada', 'calor', 'incendio'])
            ->orderByRaw("CASE severidad WHEN 'alta' THEN 0 WHEN 'moderada' THEN 1 ELSE 2 END")->orderBy('desde')
            ->get(['tipo', 'severidad', 'titulo', 'fuente', 'fuente_url', 'desde', 'hasta']);
        $lluvia = $estado->lluvia ? json_decode($estado->lluvia, true) : null;
        $enso = $estado->enso ? json_decode($estado->enso, true) : null;

        return [
            'disponible' => true,
            'zona' => $estado->zona,
            'calculado_en' => Formato::fechaHora(Carbon::parse($estado->calculado_en)),
            'alertas' => $alertas->map(fn ($a) => (array) $a)->all(),
            'lluvia' => $lluvia === null ? null : ['ventana_dias' => $lluvia['ventana_dias'] ?? null, 'anomalia_pct' => $lluvia['anomalia_pct'] ?? null,
                'acumulada_mm' => $lluvia['acumulada_mm'] ?? null, 'normal_mm' => $lluvia['normal_mm'] ?? null, 'fuente' => 'Open-Meteo (reanálisis ERA5)'],
            'enso' => $enso === null ? null : ['fase' => $enso['fase'] ?? null, 'oni' => $enso['oni'] ?? null, 'fuente' => 'NOAA CPC (ONI)'],
        ];
    }

    /**
     * Escenario que más se parece a las señales climáticas vigentes. Es una referencia para leer los escenarios, no un pronóstico de pasto.
     *
     * @param  array<string, mixed>  $clima
     * @return array{codigo: string, motivo: string}
     */
    private function escenarioCercano(array $clima): array
    {
        if (! ($clima['disponible'] ?? false)) {
            return ['codigo' => 'base', 'motivo' => 'Sin contexto climático calculado: se toma el escenario base como referencia.'];
        }
        $anomalia = $clima['lluvia']['anomalia_pct'] ?? null;
        $tipos = array_column($clima['alertas'], 'tipo');
        if ($anomalia !== null && $anomalia <= 40) {
            return ['codigo' => 'severo', 'motivo' => 'La lluvia de los últimos '.($clima['lluvia']['ventana_dias'] ?? 60).' días fue '.self::n((float) $anomalia, 0).' % de lo normal para la zona (reanálisis, no pluviómetro del campo).'];
        }
        if (in_array('sequia', $tipos, true) || ($anomalia !== null && $anomalia <= 60)) {
            return ['codigo' => 'moderado', 'motivo' => 'Hay señales de déficit de lluvia en la zona'.($anomalia !== null ? ' ('.self::n((float) $anomalia, 0).' % de lo normal)' : '').'.'];
        }
        if (array_intersect(['lluvia', 'helada'], $tipos) !== []) {
            return ['codigo' => 'moderado', 'motivo' => 'Hay una alerta de '.(in_array('lluvia', $tipos, true) ? 'lluvia extrema (posible anegamiento de potreros)' : 'helada').' que puede reducir el pasto disponible por unos días.'];
        }

        return ['codigo' => 'base', 'motivo' => 'No hay alertas climáticas que afecten el pasto en este momento.'];
    }

    /**
     * Protocolo de preguntas para el asesor (piloto). Solo formula preguntas; nunca indica qué hacer.
     *
     * @return list<string>
     */
    private function preguntas(array $inventario, array $recept, array $escenarios, array $reservas, array $clima, array $usados): array
    {
        $q = [];
        if ($recept['valor'] > 0) {
            $q[] = '¿La receptividad declarada ('.self::n((float) $recept['valor'], 2).' EV/ha'.($recept['periodo'] ? ", {$recept['periodo']}" : '').') sigue representando el estado actual de las pasturas?';
        }
        $q[] = '¿Son razonables para tus categorías y para esta época el consumo de '.self::n($usados['consumo_ms_kg_ev_dia']['valor'], 1).' kg MS/EV/día y las equivalencias EV usadas?';
        if (in_array('BUB', array_column($inventario, 'especie'), true)) {
            $q[] = 'Las equivalencias EV de búfalos son orientativas y no están validadas: ¿cuáles conviene usar en tu rodeo?';
        }
        $conFaltante = array_values(array_filter($escenarios, fn ($e) => in_array($e['estado'], ['parcial', 'sin_reservas'], true)));
        if ($conFaltante !== []) {
            $q[] = 'Si se diera el escenario «'.$conFaltante[0]['nombre'].'», ¿qué alternativas conviene evaluar (reservas adicionales, suplementación, redistribución o ajuste de carga), en qué orden y con qué costo?';
        }
        if ($reservas === []) {
            $q[] = '¿Hay rollos, silo o suplemento disponibles que no estén cargados en este brief?';
        } else {
            $q[] = '¿En qué estado están las reservas (calidad, pérdidas, acceso con lluvia) y cuánto aprovechamiento es realista?';
        }
        foreach (array_slice($clima['alertas'] ?? [], 0, 2) as $a) {
            $q[] = "Con la alerta «{$a['titulo']}» vigente: ¿cómo quedan aguadas, potreros bajos y accesos para mover hacienda o reservas?";
        }

        return $q;
    }

    /** @return array<string, mixed>|null */
    private function reservasUltimoBrief(Establecimiento $est): ?array
    {
        $ultimo = DB::table('forraje_briefs')->where('establecimiento_id', $est->id)->orderByDesc('created_at')->orderByDesc('id')->value('entradas');

        return $ultimo ? (json_decode($ultimo, true)['reservas'] ?? null) : null;
    }

    private static function d(?float $v, int $decimales): ?string
    {
        return $v === null ? null : Formato::decimal(round($v, $decimales));
    }

    private static function n(float $v, int $decimales = 1): string
    {
        return number_format(round($v, $decimales), $decimales, ',', '.');
    }
}
