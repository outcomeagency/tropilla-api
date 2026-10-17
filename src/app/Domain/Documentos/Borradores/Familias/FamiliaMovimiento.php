<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Documentos\Borradores\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Documentos de movimiento de hacienda (TPA-64, senasa-planillas.md §2 y §9): DT-e de SENASA y guías provinciales.
 * TROPILLA **no emite ni valida** estos documentos: transcribe la constancia que la persona ya tiene, la concilia con
 * sus cabezas y la relaciona con el movimiento. Además de lo leído, la persona define en el grupo «movimiento»:
 * - sentido (egreso/ingreso, sugerido por el RENSPA de origen/destino),
 * - registro: `vincular` a un movimiento ya cargado (sugerido si alguno tiene el mismo N.º de DT-e) o `registrar` uno
 *   nuevo (ingreso/egreso con conteo en un lote anónimo, o egreso de animales por caravana).
 * Suma del detalle ≠ total y cabezas distintas al movimiento vinculado son advertencias: nada se corrige solo.
 */
abstract class FamiliaMovimiento extends FamiliaBase
{
    public const MOTIVOS = ['faena', 'invernada', 'cria', 'remate_feria', 'exposicion', 'mismo_titular', 'exportacion', 'venta', 'otro'];

    protected const ETIQUETAS = [
        'faena' => 'Faena', 'invernada' => 'Invernada', 'cria' => 'Cría', 'remate_feria' => 'Remate feria', 'exposicion' => 'Exposición',
        'mismo_titular' => 'Traslado entre campos del mismo titular', 'exportacion' => 'Exportación', 'venta' => 'Venta', 'otro' => 'Otro',
        'egreso' => 'Sale del campo (egreso)', 'ingreso' => 'Entra al campo (ingreso)',
        'vincular' => 'Ya está cargado en TROPILLA: vincularlo', 'registrar' => 'Registrar el movimiento ahora',
    ];

    protected const SINONIMOS = [
        'faena' => ['faena', 'frigor', 'matadero'], 'invernada' => ['invern', 'engorde'], 'cria' => ['cria', 'reproduc'],
        'remate_feria' => ['remate', 'feria'], 'exposicion' => ['exposic'], 'mismo_titular' => ['mismo titular', 'propio', 'entre campos'],
        'exportacion' => ['export'], 'venta' => ['venta'],
    ];

    /** Advertencias que se corrigen antes de confirmar (no se aceptan). */
    public const BLOQUEANTES = [
        'categoria_otra_especie', 'movimiento_sin_definir', 'sentido_sin_definir', 'movimiento_sin_elegir', 'movimiento_inexistente',
        'lote_sin_elegir', 'lote_no_anonimo', 'lote_otra_especie', 'caravana_desconocida', 'detalle_sin_categoria',
    ];

    /** N.º del DT-e que respalda el movimiento (el propio en un DT-e; el asociado en una guía). */
    abstract protected function numeroDte(array $campos): ?string;

    /** Nombre corto para mensajes («el DT-e», «la guía»). */
    abstract protected function nombreCorto(): string;

    protected function filasDef(): array
    {
        return ['detalle' => [
            'especie' => ['Especie', 'opcion', ['BOV', 'BUB'], true],
            'categoria' => ['Categoría (como figura)', 'texto', null, false],
            'categoria_codigo' => ['Categoría en TROPILLA', 'categoria', null, true],
            'cantidad' => ['Cabezas', 'entero', null, true],
            'marca' => ['Marca / señal', 'texto', null, false],
        ]];
    }

    /** Campos comunes de origen, destino, transporte y totales. */
    protected function comunes(): array
    {
        return [
            'renspa_origen' => ['origen', 'RENSPA de origen', 'texto', null, true],
            'titular_origen' => ['origen', 'Titular / remitente', 'texto', null, false],
            'cuit_origen' => ['origen', 'CUIT del remitente', 'texto', null, false],
            'establecimiento_origen' => ['origen', 'Establecimiento de origen', 'texto', null, false],
            'localidad_origen' => ['origen', 'Localidad / departamento de origen', 'texto', null, false],
            'renspa_destino' => ['destino', 'RENSPA de destino', 'texto', null, false],
            'titular_destino' => ['destino', 'Destinatario', 'texto', null, false],
            'cuit_destino' => ['destino', 'CUIT del destinatario', 'texto', null, false],
            'establecimiento_destino' => ['destino', 'Establecimiento de destino', 'texto', null, false],
            'localidad_destino' => ['destino', 'Localidad / departamento de destino', 'texto', null, false],
            'transportista' => ['transporte', 'Transportista', 'texto', null, false],
            'cuit_transportista' => ['transporte', 'CUIT del transportista', 'texto', null, false],
            'patentes' => ['transporte', 'Patentes (chasis / acoplado)', 'texto', null, false],
            'total_cabezas' => ['totales', 'Total de cabezas', 'entero', null, true],
        ];
    }

    protected function propios(): array
    {
        return [
            'movimiento_sentido' => ['movimiento', 'Para este campo, la hacienda…', 'opcion', ['egreso', 'ingreso'], true],
            'movimiento_registro' => ['movimiento', 'En TROPILLA', 'opcion', ['vincular', 'registrar'], true],
            'movimiento_evento_id' => ['movimiento', 'Movimiento ya cargado', 'evento', [], false],
            'movimiento_lote_id' => ['movimiento', 'Lote', 'lote', [], false],
            'movimiento_caravanas' => ['movimiento', 'Caravanas que salen (opcional, en lugar del lote)', 'texto', null, false],
        ];
    }

    protected function titulosGrupos(): array
    {
        return ['origen' => 'Origen', 'destino' => 'Destino', 'transporte' => 'Transporte', 'detalle' => 'Detalle :n',
            'totales' => 'Totales', 'intervenciones' => 'Intervenciones y talones', 'sanitario' => 'Requisitos sanitarios', 'movimiento' => 'Movimiento en TROPILLA'];
    }

    protected function etiquetaAgregar(string $grupo): string
    {
        return 'Agregar categoría';
    }

    public function bloqueantes(): array
    {
        return self::BLOQUEANTES;
    }

    public function completar(Establecimiento $est, array $campos): array
    {
        $propio = self::renspaPropio($est);
        $v = fn (string $r) => Campos::valor($campos, $r);
        $sentido = match (true) {
            self::mismoRenspa($propio, $v('renspa_origen')) => 'egreso',
            self::mismoRenspa($propio, $v('renspa_destino')) => 'ingreso',
            default => null,
        };
        self::sugerir($campos, 'movimiento_sentido', $sentido);
        $existente = self::movimientoConDte($est->id, $this->numeroDte($campos));
        self::sugerir($campos, 'movimiento_registro', $existente ? 'vincular' : 'registrar');
        self::sugerir($campos, 'movimiento_evento_id', $existente?->id);

        return $campos;
    }

    public function refrescarOpciones(string $establecimientoId, array $campos): array
    {
        $campos = parent::refrescarOpciones($establecimientoId, $campos);
        $elegido = Campos::valor($campos, 'movimiento_evento_id');
        foreach ($campos as &$c) {
            if ($c['tipo'] === 'evento') {
                $c['opciones'] = self::opcionesMovimiento($establecimientoId, is_string($elegido) ? $elegido : null);
            }
        }
        unset($c);

        return $campos;
    }

    public function advertencias(Establecimiento $est, array $campos, array $salida): array
    {
        $v = fn (string $r) => Campos::valor($campos, $r);
        $adv = $this->advertenciasLectura($salida, $this->nombreCorto().' '.__('conocido'));
        if ($a = $this->advertenciaFecha($v('fecha_emision'), 'fecha_emision', __('la fecha de emisión'))) {
            $adv[] = $a;
        }
        $propio = self::renspaPropio($est);
        foreach (['renspa_origen' => __('origen'), 'renspa_destino' => __('destino')] as $ruta => $lado) {
            if (($r = $v($ruta)) !== null && ! self::renspaValido($r)) {
                $adv[] = ['codigo' => 'renspa_formato', 'mensaje' => __('El RENSPA de :l no tiene el formato 00.000.0.00000/00.', ['l' => $lado]), 'ruta' => $ruta];
            }
        }
        if ($propio && ($v('renspa_origen') || $v('renspa_destino')) && ! self::mismoRenspa($propio, $v('renspa_origen')) && ! self::mismoRenspa($propio, $v('renspa_destino'))) {
            $adv[] = ['codigo' => 'renspa_ajeno', 'mensaje' => __('Ni el origen ni el destino tienen el RENSPA de este establecimiento.'), 'ruta' => 'renspa_origen',
                'detalle' => ['establecimiento' => $propio]];
        }

        // Detalle: suma vs total, categorías del catálogo y especie.
        $porCategoria = [];
        $especies = [];
        $suma = 0;
        for ($i = 0, $n = Campos::filas($campos, 'detalle'); $i < $n; $i++) {
            $cant = $v("detalle.{$i}.cantidad");
            $suma += (int) $cant;
            $cat = $v("detalle.{$i}.categoria_codigo");
            $esp = $v("detalle.{$i}.especie");
            if ($cat !== null && $esp !== null && ! str_starts_with($cat, $esp.'_')) {
                $adv[] = ['codigo' => 'categoria_otra_especie', 'mensaje' => __('Fila :n: la categoría elegida es de otra especie.', ['n' => $i + 1]), 'ruta' => "detalle.{$i}.categoria_codigo"];
            }
            if ($cant !== null && $cant > 0) {
                if ($cat === null) {
                    $adv[] = ['codigo' => 'detalle_sin_categoria', 'mensaje' => __('Fila :n: elegí la categoría de TROPILLA.', ['n' => $i + 1]), 'ruta' => "detalle.{$i}.categoria_codigo"];
                } else {
                    $porCategoria[$cat] = ($porCategoria[$cat] ?? 0) + $cant;
                    $especies[substr($cat, 0, 3)] = true;
                }
            }
        }
        $total = $v('total_cabezas');
        if ($total !== null && $n > 0 && $total !== $suma) {
            $adv[] = ['codigo' => 'totales_no_cierran', 'mensaje' => __('La suma del detalle (:s) no coincide con el total (:t).', ['s' => $suma, 't' => $total]),
                'ruta' => 'total_cabezas', 'detalle' => ['suma' => $suma, 'total' => $total]];
        }
        $cabezas = $total ?? $suma;

        return [...$adv, ...$this->advertenciasMovimiento($est, $campos, $porCategoria, array_keys($especies), $cabezas, $propio)];
    }

    /** Conciliación con TROPILLA según lo que la persona eligió en el grupo «movimiento». */
    private function advertenciasMovimiento(Establecimiento $est, array $campos, array $porCategoria, array $especies, int $cabezas, ?string $propio): array
    {
        $v = fn (string $r) => Campos::valor($campos, $r);
        $adv = [];
        $sentido = $v('movimiento_sentido');
        $registro = $v('movimiento_registro');
        if ($registro === null) {
            return [['codigo' => 'movimiento_sin_definir', 'mensaje' => __('Elegí si el movimiento ya está cargado o si lo registrás ahora.'), 'ruta' => 'movimiento_registro']];
        }
        if ($sentido === null) {
            $adv[] = ['codigo' => 'sentido_sin_definir', 'mensaje' => __('Elegí si la hacienda sale o entra a este campo.'), 'ruta' => 'movimiento_sentido'];
        } elseif ($propio && self::mismoRenspa($propio, $v($sentido === 'egreso' ? 'renspa_destino' : 'renspa_origen'))) {
            $adv[] = ['codigo' => 'sentido_no_coincide', 'mensaje' => __('El RENSPA de este campo figura del otro lado: revisá si la hacienda sale o entra.'), 'ruta' => 'movimiento_sentido'];
        }

        if ($registro === 'vincular') {
            $eventoId = $v('movimiento_evento_id');
            $mov = $eventoId === null ? null : self::movimiento($est->id, $eventoId);
            if ($eventoId === null) {
                $adv[] = ['codigo' => 'movimiento_sin_elegir', 'mensaje' => __('Elegí el movimiento ya cargado al que pertenece :d.', ['d' => $this->nombreCorto()]), 'ruta' => 'movimiento_evento_id'];
            } elseif ($mov === null) {
                $adv[] = ['codigo' => 'movimiento_inexistente', 'mensaje' => __('Ese movimiento no existe o fue anulado.'), 'ruta' => 'movimiento_evento_id'];
            } else {
                $c = self::cabezasEvento($mov);
                if ($c['total'] !== $cabezas || ($porCategoria !== [] && $c['por_categoria'] !== [] && self::ordenado($c['por_categoria']) !== self::ordenado($porCategoria))) {
                    $adv[] = ['codigo' => 'cantidades_no_concilian', 'ruta' => 'movimiento_evento_id',
                        'mensaje' => __(':Doc declara :d cabezas y el movimiento cargado tiene :t.', ['doc' => $this->nombreCorto(), 'd' => $cabezas, 't' => $c['total']]),
                        'detalle' => ['documento' => $porCategoria, 'tropilla' => $c['por_categoria'], 'total_documento' => $cabezas, 'total_tropilla' => $c['total']]];
                }
                $dteEvento = json_decode($mov->payload, true)['dte_numero'] ?? null;
                $dte = $this->numeroDte($campos);
                if ($dteEvento !== null && $dte !== null && self::sinFormato($dteEvento) !== self::sinFormato($dte)) {
                    $adv[] = ['codigo' => 'dte_no_coincide', 'ruta' => 'movimiento_evento_id',
                        'mensaje' => __('El movimiento cargado tiene el DT-e :e y el documento dice :d.', ['e' => $dteEvento, 'd' => $dte])];
                }
                $tipo = $sentido === 'ingreso' ? 'hacienda.ingreso' : 'hacienda.egreso';
                if ($sentido !== null && $mov->tipo !== $tipo) {
                    $adv[] = ['codigo' => 'sentido_no_coincide', 'mensaje' => __('El movimiento elegido es un :t y el documento dice lo contrario.', ['t' => $mov->tipo === 'hacienda.ingreso' ? 'ingreso' : 'egreso']),
                        'ruta' => 'movimiento_evento_id'];
                }
            }

            return $adv;
        }

        // Registrar uno nuevo: egreso por caravanas, o ingreso/egreso con conteo de un lote anónimo.
        $caravanas = $sentido === 'egreso' ? $v('movimiento_caravanas') : null;
        if ($caravanas !== null) {
            if ($a = $this->advertenciaCaravanas($est, $caravanas, 'movimiento_caravanas', __('Caravanas'))) {
                $adv[] = $a;
            } elseif (($n = count(self::resolverCaravanas($est, $caravanas)['ids'])) !== $cabezas) {
                $adv[] = ['codigo' => 'cantidades_no_concilian', 'ruta' => 'movimiento_caravanas',
                    'mensaje' => __(':Doc declara :d cabezas y anotaste :t caravanas.', ['doc' => $this->nombreCorto(), 'd' => $cabezas, 't' => $n])];
            }

            return $adv;
        }
        $lote = self::lote($est, $v('movimiento_lote_id'));
        if ($lote === null) {
            $adv[] = ['codigo' => 'lote_sin_elegir', 'mensaje' => $sentido === 'egreso'
                ? __('Elegí el lote del que sale la hacienda o anotá las caravanas.') : __('Elegí el lote al que entra la hacienda.'), 'ruta' => 'movimiento_lote_id'];

            return $adv;
        }
        if (! $lote->anonimo) {
            $adv[] = ['codigo' => 'lote_no_anonimo', 'mensaje' => $sentido === 'egreso'
                ? __('Ese lote tiene animales individualizados: anotá las caravanas que salen.') : __('Las cabezas sin caravana entran a un lote por conteo: elegí un lote por conteo.'), 'ruta' => 'movimiento_lote_id'];
        }
        if (array_diff($especies, [$lote->especie_codigo]) !== []) {
            $adv[] = ['codigo' => 'lote_otra_especie', 'mensaje' => __('El lote es de otra especie que la hacienda del documento.'), 'ruta' => 'movimiento_lote_id'];
        }
        if ($sentido === 'egreso' && $lote->anonimo) {
            $hay = DB::table('lote_conteos')->where('lote_id', $lote->id)->pluck('cabezas', 'categoria_codigo')->all();
            foreach ($porCategoria as $cat => $n) {
                if ((int) ($hay[$cat] ?? 0) < $n) {
                    $adv[] = ['codigo' => 'conteo_insuficiente', 'ruta' => 'movimiento_lote_id',
                        'mensaje' => __('El lote tiene :h cabezas de :c y salen :n: el conteo quedaría negativo.', ['h' => (int) ($hay[$cat] ?? 0), 'c' => $cat, 'n' => $n])];
                }
            }
        }

        return $adv;
    }

    public function confirmacion(Establecimiento $est, array $campos, string $documentoId): array
    {
        $v = fn (string $r) => Campos::valor($campos, $r);
        if ($v('movimiento_registro') === 'vincular') {
            $mov = self::movimiento($est->id, (string) $v('movimiento_evento_id')) ?? throw ApiException::reglaBloqueante(__('Ese movimiento no existe o fue anulado.'));

            return ['eventos' => [], 'vincular' => [$mov->id]];
        }
        $sentido = $v('movimiento_sentido') ?? throw ApiException::reglaBloqueante(__('Elegí si la hacienda sale o entra a este campo.'));
        $fecha = $v('fecha_emision') ?? throw ApiException::reglaBloqueante(__('Falta la fecha de emisión.'));
        $conteo = [];
        for ($i = 0, $n = Campos::filas($campos, 'detalle'); $i < $n; $i++) {
            if (($cant = $v("detalle.{$i}.cantidad")) > 0 && ($cat = $v("detalle.{$i}.categoria_codigo")) !== null) {
                $conteo[$cat] = ($conteo[$cat] ?? 0) + $cant;
            }
        }
        $ids = $sentido === 'egreso' ? self::resolverCaravanas($est, $v('movimiento_caravanas'))['ids'] : [];
        if ($ids !== []) {
            $sujetos = array_map(fn ($t) => ['tipo' => 'animales', 'animal_ids' => $t], array_chunk($ids, 1000));
        } else {
            if ($conteo === []) {
                throw ApiException::reglaBloqueante(__('El detalle no tiene cabezas con categoría para registrar.'));
            }
            ksort($conteo);
            $sujetos = [['tipo' => 'lote', 'lote_id' => (string) $v('movimiento_lote_id'), 'conteo' => (object) $conteo]];
        }
        $t = fn (?string $x) => $x === null ? null : mb_substr($x, 0, 200);
        $motivo = $v('motivo');
        $dte = $t($this->numeroDte($campos));
        $payload = $sentido === 'ingreso'
            ? array_filter([
                'motivo' => $motivo === 'mismo_titular' ? 'traslado_externo' : 'compra',
                'origen' => array_filter(['vendedor' => $t($v('titular_origen')), 'establecimiento' => $t($v('establecimiento_origen')),
                    'renspa_declarado' => $t($v('renspa_origen')), 'localidad' => $t($v('localidad_origen'))]) ?: null,
                'dte_numero' => $dte, 'documento_ids' => [$documentoId],
            ], fn ($x) => $x !== null)
            : array_filter([
                'motivo' => match ($motivo) {
                    'mismo_titular' => 'traslado_externo',
                    'exposicion', 'otro' => 'otro',
                    default => 'venta',
                },
                'destino' => in_array($motivo, ['faena', 'invernada', 'cria', 'exportacion'], true) ? $motivo : null,
                'comprador' => $t($v('titular_destino')), 'destino_establecimiento' => $t($v('establecimiento_destino')),
                'renspa_destino_declarado' => $t($v('renspa_destino')), 'dte_numero' => $dte, 'documento_ids' => [$documentoId],
            ], fn ($x) => $x !== null);
        $obs = __('Transcripción asistida de :d:n, revisada campo por campo. TROPILLA no emite ni valida documentos oficiales.',
            ['d' => $this->nombreCorto(), 'n' => $this->numero($campos) ? ' N.º '.$this->numero($campos) : '']);

        return ['eventos' => [[
            'tipo' => $sentido === 'ingreso' ? 'hacienda.ingreso' : 'hacienda.egreso', 'ocurrido_en' => $fecha, 'payload' => $payload,
            'sujetos' => $sujetos, 'observaciones' => mb_substr($obs, 0, 2000),
        ]], 'vincular' => []];
    }

    protected function numero(array $campos): ?string
    {
        return Campos::valor($campos, 'numero');
    }

    // ── consultas ────────────────────────────────────────────────────────────────────────────────────────────────

    /** Versión vigente (no anulada) de un ingreso/egreso del establecimiento, por id de cualquiera de sus versiones. */
    public static function movimiento(string $establecimientoId, string $eventoId): ?object
    {
        $raiz = DB::table('eventos')->where('id', strtolower($eventoId))->where('establecimiento_id', $establecimientoId)->value('evento_raiz_id');

        return $raiz === null ? null : DB::table('eventos_vigentes')->where('evento_raiz_id', $raiz)->whereIn('tipo', ['hacienda.ingreso', 'hacienda.egreso'])
            ->where('accion', '<>', 'anulacion')->first();
    }

    public static function movimientoConDte(string $establecimientoId, ?string $dte): ?object
    {
        if ($dte === null || self::sinFormato($dte) === '') {
            return null;
        }

        return DB::table('eventos_vigentes')->where('establecimiento_id', $establecimientoId)->whereIn('tipo', ['hacienda.ingreso', 'hacienda.egreso'])
            ->where('accion', '<>', 'anulacion')->whereNotNull(DB::raw("payload->>'dte_numero'"))
            ->whereRaw("regexp_replace(upper(payload->>'dte_numero'), '[^0-9A-Z]', '', 'g') = ?", [self::sinFormato($dte)])
            ->orderByDesc('ocurrido_en')->first();
    }

    /** @return array{total: int, por_categoria: array<string, int>} */
    public static function cabezasEvento(object $mov): array
    {
        if ($mov->conteo !== null) {
            $c = array_map('intval', json_decode($mov->conteo, true));

            return ['total' => array_sum($c), 'por_categoria' => self::ordenado($c)];
        }
        $c = DB::table('evento_animales as ea')->join('animales_estado as a', 'a.animal_id', '=', 'ea.animal_id')->where('ea.evento_id', $mov->id)
            ->groupBy('a.categoria_codigo')->selectRaw('a.categoria_codigo, count(*) as n')->pluck('n', 'categoria_codigo')->map(fn ($n) => (int) $n)->all();
        $total = (int) DB::table('evento_animales')->where('evento_id', $mov->id)->count();

        return ['total' => $total, 'por_categoria' => self::ordenado($c)];
    }

    /** @return list<array{valor: string, etiqueta: string}> ingresos/egresos vigentes del último año (+ el elegido). */
    public static function opcionesMovimiento(string $establecimientoId, ?string $elegido): array
    {
        $movs = DB::table('eventos_vigentes')->where('establecimiento_id', $establecimientoId)->whereIn('tipo', ['hacienda.ingreso', 'hacienda.egreso'])
            ->where('accion', '<>', 'anulacion')->where('ocurrido_en', '>=', now()->subDays(400))->orderByDesc('ocurrido_en')->limit(60)->get();
        if ($elegido !== null && ! $movs->contains('id', $elegido) && ($m = self::movimiento($establecimientoId, $elegido))) {
            $movs->prepend($m);
        }

        return $movs->map(function ($m) {
            $dte = json_decode($m->payload, true)['dte_numero'] ?? null;

            return ['valor' => $m->id, 'etiqueta' => implode(' · ', array_filter([
                $m->tipo === 'hacienda.ingreso' ? 'Ingreso' : 'Egreso', Carbon::parse($m->ocurrido_en)->format('d/m/Y'),
                self::cabezasEvento($m)['total'].' cab.', $dte ? 'DT-e '.$dte : null,
            ]))];
        })->values()->all();
    }

    private static function sinFormato(string $s): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', strtoupper($s));
    }

    private static function ordenado(array $a): array
    {
        $a = array_filter($a, fn ($n) => $n !== 0);
        ksort($a);

        return $a;
    }
}
