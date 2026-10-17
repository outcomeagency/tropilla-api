<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Documentos\Borradores\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;

/**
 * FIDHA — Formulario de Inspección y Despacho de Hacienda (garrapata; TPA-65, Res. SENASA 655/2026 y 917/2024;
 * senasa-planillas.md §7). Lo completa el personal oficial; TROPILLA conserva la foto y transcribe:
 * inspección (N.º, fecha, lugar, zona, resultado, causa de rechazo, agente y firma), tropa (origen, destino, DT-e,
 * especie, cabezas) y tratamiento precaucional (producto comercial, principio activo, fecha y método).
 * Esquemas: `fidha.655_2026/1` (vigente desde julio de 2026) y `fidha.anterior/1` (modelo previo: se lee igual y se
 * advierte). Confirmar registra, sobre el lote o las caravanas elegidas, la inspección (`sanidad.observacion_clinica`
 * con el signo «inspección de garrapata») y, si hubo, el tratamiento (`sanidad.desparasitacion` externo); si un
 * movimiento cargado tiene el mismo N.º de DT-e, el documento también queda vinculado a él. Nada de esto es el FIDHA.
 */
final class Fidha extends FamiliaBase
{
    public const ESQUEMA = 'fidha.655_2026/1';

    public const ESQUEMA_ANTERIOR = 'fidha.anterior/1';

    public const SIGNO = 'inspección de garrapata';

    protected const ETIQUETAS = [
        'negativo' => 'Negativo (sin garrapatas)', 'positivo' => 'Positivo (con garrapatas)', 'rechazada' => 'Tropa rechazada',
        'inmersion' => 'Baño de inmersión', 'aspersion' => 'Aspersión', 'pour_on' => 'Pour-on / dorsal', 'inyectable' => 'Inyectable', 'otro' => 'Otro',
    ];

    protected const SINONIMOS = [
        'rechazada' => ['rechaz'], 'positivo' => ['positiv', 'con garrap'], 'negativo' => ['negativ', 'sin garrap', 'libre'],
        'inmersion' => ['inmers', 'banad', 'bano'], 'aspersion' => ['aspers', 'mochila'], 'pour_on' => ['pour', 'dorsal'], 'inyectable' => ['inyect', 'subcut'],
    ];

    public function codigo(): string
    {
        return 'fidha';
    }

    public function tipoDocumento(): string
    {
        return 'fidha';
    }

    protected function variantes(): array
    {
        return ['655_2026' => self::ESQUEMA, 'anterior' => self::ESQUEMA_ANTERIOR];
    }

    protected function escalares(string $variante): array
    {
        return [
            'numero' => ['inspeccion', 'N.º de FIDHA', 'texto', null, true],
            'fecha_inspeccion' => ['inspeccion', 'Fecha de inspección', 'fecha', null, true],
            'lugar_inspeccion' => ['inspeccion', 'Lugar de inspección', 'texto', null, false],
            'zona_origen' => ['inspeccion', 'Zona de origen (garrapata)', 'texto', null, false],
            'resultado' => ['inspeccion', 'Resultado de la inspección', 'opcion', ['negativo', 'positivo', 'rechazada'], true],
            'causa_rechazo' => ['inspeccion', 'Causa del rechazo', 'texto', null, false],
            'agente_nombre' => ['inspeccion', 'Agente que inspeccionó', 'texto', null, false],
            'agente_identificacion' => ['inspeccion', 'Identificación del agente', 'texto', null, false],
            'firma_agente' => ['inspeccion', 'Firma del agente', 'booleano', null, false],
            'renspa_origen' => ['tropa', 'RENSPA de origen', 'texto', null, false],
            'establecimiento_origen' => ['tropa', 'Establecimiento de origen', 'texto', null, false],
            'titular' => ['tropa', 'Titular', 'texto', null, false],
            'renspa_destino' => ['tropa', 'RENSPA de destino', 'texto', null, false],
            'destino' => ['tropa', 'Destino', 'texto', null, false],
            'dte_numero' => ['tropa', 'N.º de DT-e asociado', 'texto', null, false],
            'especie' => ['tropa', 'Especie', 'opcion', ['BOV', 'BUB'], false],
            'cantidad' => ['tropa', 'Cabezas de la tropa', 'entero', null, true],
            'tratamiento_precaucional' => ['tratamiento', 'Hubo tratamiento precaucional', 'booleano', null, true],
            'producto_comercial' => ['tratamiento', 'Producto (nombre comercial)', 'texto', null, false],
            'principio_activo' => ['tratamiento', 'Principio activo', 'texto', null, false],
            'fecha_tratamiento' => ['tratamiento', 'Fecha del tratamiento', 'fecha', null, false],
            'metodo' => ['tratamiento', 'Método', 'opcion', ['inmersion', 'aspersion', 'pour_on', 'inyectable', 'otro'], false],
            'observaciones' => ['tratamiento', 'Observaciones', 'texto', null, false],
        ];
    }

    protected function propios(): array
    {
        return [
            'lote_id' => ['animales', 'Lote inspeccionado', 'lote', [], false],
            'caravanas' => ['animales', 'Caravanas (opcional, en lugar del lote)', 'texto', null, false],
        ];
    }

    public function instrucciones(): string
    {
        return <<<'TXT'
        Es un FIDHA (Formulario de Inspección y Despacho de Hacienda) de SENASA, Argentina, del control de garrapata del bovino.
        variante: "655_2026" si es el modelo vigente de la Res. 655/2026 (desde julio de 2026); "anterior" si es un modelo
        previo; "desconocida" si no es un FIDHA.
        Transcribí cada dato tal como figura (fechas y números como están, sin reformatear). Para cada campo: valor (null si
        está vacío, tachado o ilegible), confianza entre 0 y 1 (manuscrito dudoso ≤ 0.6), pagina (1..n) y alternativas si un
        trazo admite otra lectura.
        Campos: numero del FIDHA, fecha_inspeccion, lugar_inspeccion, zona_origen (zona de control, erradicación, indemne u
        otra como figure), resultado (negativo, positivo o rechazada como figure), causa_rechazo, agente_nombre,
        agente_identificacion, firma_agente ("si" si se ve la firma, "no" si el lugar está vacío), renspa_origen,
        establecimiento_origen, titular, renspa_destino, destino, dte_numero (DT-e asociado), especie (bovino o bubalino),
        cantidad (cabezas de la tropa), tratamiento_precaucional ("si"/"no"), producto_comercial, principio_activo,
        fecha_tratamiento, metodo (inmersión, aspersión, pour-on, inyectable u otro) y observaciones.
        problemas: páginas cortadas, borrosas, con reflejo o sombra que impidan leer algo.
        TXT;
    }

    protected function nombreEsquema(string $esquema): string
    {
        return $esquema === self::ESQUEMA ? 'FIDHA (Res. 655/2026)' : 'FIDHA (modelo anterior)';
    }

    protected function titulosGrupos(): array
    {
        return ['inspeccion' => 'Inspección', 'tropa' => 'Tropa', 'tratamiento' => 'Tratamiento precaucional', 'animales' => 'Animales en TROPILLA'];
    }

    public function bloqueantes(): array
    {
        return ['caravana_desconocida', 'sin_animales'];
    }

    public function advertencias(Establecimiento $est, array $campos, array $salida): array
    {
        $v = fn (string $r) => Campos::valor($campos, $r);
        $adv = $this->advertenciasLectura($salida, __('un FIDHA'));
        if (($salida['variante'] ?? null) === 'anterior') {
            $adv[] = ['codigo' => 'formulario_anterior', 'mensaje' => __('Es un FIDHA anterior a la Res. 655/2026: revisá que los datos sigan valiendo.')];
        }
        if ($a = $this->advertenciaFecha($v('fecha_inspeccion'), 'fecha_inspeccion', __('la fecha de inspección'))) {
            $adv[] = $a;
        }
        if ($v('renspa_origen') !== null && ! self::renspaValido($v('renspa_origen'))) {
            $adv[] = ['codigo' => 'renspa_formato', 'mensaje' => __('El RENSPA de origen no tiene el formato 00.000.0.00000/00.'), 'ruta' => 'renspa_origen'];
        }
        $propio = self::renspaPropio($est);
        if ($propio && $v('renspa_origen') !== null && ! self::mismoRenspa($propio, $v('renspa_origen'))) {
            $adv[] = ['codigo' => 'renspa_distinto', 'mensaje' => __('El RENSPA de origen no es el de este establecimiento.'), 'ruta' => 'renspa_origen'];
        }
        if (in_array($v('resultado'), ['positivo', 'rechazada'], true)) {
            $adv[] = ['codigo' => 'inspeccion_positiva', 'ruta' => 'resultado',
                'mensaje' => __('La inspección dio :r: la tropa no sale hasta cumplir lo que indique SENASA.', ['r' => $v('resultado') === 'positivo' ? __('positiva') : __('rechazo')])];
        }
        if ($v('tratamiento_precaucional') === true && $v('producto_comercial') === null) {
            $adv[] = ['codigo' => 'tratamiento_sin_producto', 'ruta' => 'producto_comercial', 'mensaje' => __('Hubo tratamiento pero no figura el producto: no se registra el tratamiento.')];
        }
        if ($v('fecha_tratamiento') !== null && $v('fecha_inspeccion') !== null && $v('fecha_tratamiento') > $v('fecha_inspeccion')) {
            $adv[] = ['codigo' => 'fecha_tratamiento_posterior', 'ruta' => 'fecha_tratamiento', 'mensaje' => __('El tratamiento figura después de la inspección.')];
        }
        if ($v('firma_agente') === false) {
            $adv[] = ['codigo' => 'firma_no_detectada', 'ruta' => 'firma_agente', 'mensaje' => __('No se detectó la firma del agente.')];
        }
        if ($a = $this->advertenciaCaravanas($est, $v('caravanas'), 'caravanas', __('Caravanas'))) {
            $adv[] = $a;
        } elseif ($v('caravanas') !== null) {
            $n = count(self::resolverCaravanas($est, $v('caravanas'))['ids']);
            if ($v('cantidad') !== null && $n !== $v('cantidad')) {
                $adv[] = ['codigo' => 'cantidades_no_concilian', 'ruta' => 'caravanas', 'mensaje' => __('El FIDHA dice :d cabezas y anotaste :n caravanas.', ['d' => $v('cantidad'), 'n' => $n])];
            }
        } elseif (($lote = self::lote($est, $v('lote_id'))) === null) {
            $adv[] = ['codigo' => 'sin_animales', 'ruta' => 'lote_id', 'mensaje' => __('Elegí el lote inspeccionado o anotá las caravanas.')];
        } elseif ($v('especie') !== null && $lote->especie_codigo !== $v('especie')) {
            $adv[] = ['codigo' => 'lote_otra_especie', 'ruta' => 'lote_id', 'mensaje' => __('El lote es de otra especie que la tropa del FIDHA.')];
        }

        return $adv;
    }

    public function confirmacion(Establecimiento $est, array $campos, string $documentoId): array
    {
        $v = fn (string $r) => Campos::valor($campos, $r);
        $t = fn (?string $x) => $x === null ? null : mb_substr($x, 0, 200);
        $fecha = $v('fecha_inspeccion') ?? throw ApiException::reglaBloqueante(__('Falta la fecha de inspección.'));
        $resultado = $v('resultado') ?? throw ApiException::reglaBloqueante(__('Falta el resultado de la inspección.'));
        $sujeto = $this->sujetoSanitario($est, $v('caravanas'), $v('lote_id'), __('Animales'));
        $numero = $v('numero') ? ' N.º '.$v('numero') : '';
        $aviso = __('Transcripción asistida del FIDHA:n, revisada campo por campo. No reemplaza al formulario oficial.', ['n' => $numero]);
        $descripcion = implode(' ', array_filter([
            __('Inspección de garrapata (FIDHA:n): :r.', ['n' => $numero, 'r' => mb_strtolower(self::ETIQUETAS[$resultado])]),
            $v('causa_rechazo') ? __('Causa: :c.', ['c' => $v('causa_rechazo')]) : null,
            $v('agente_nombre') ? __('Agente: :a:i.', ['a' => $v('agente_nombre'), 'i' => $v('agente_identificacion') ? ' ('.$v('agente_identificacion').')' : '']) : null,
            $v('lugar_inspeccion') ? __('Lugar: :l.', ['l' => $v('lugar_inspeccion')]) : null,
            $v('destino') ? __('Destino: :d.', ['d' => $v('destino')]) : null,
            $v('dte_numero') ? __('DT-e :d.', ['d' => $v('dte_numero')]) : null,
            $v('cantidad') !== null ? __(':c cabezas.', ['c' => $v('cantidad')]) : null,
        ]));
        $eventos = [[
            'tipo' => 'sanidad.observacion_clinica', 'ocurrido_en' => $fecha, 'sujetos' => [$sujeto], 'observaciones' => $aviso,
            'payload' => ['signos' => [self::SIGNO.': '.$resultado], 'descripcion' => mb_substr($descripcion, 0, 2000)],
        ]];
        if ($v('tratamiento_precaucional') === true && $v('producto_comercial') !== null) {
            $metodo = $v('metodo');
            $eventos[] = [
                'tipo' => 'sanidad.desparasitacion', 'ocurrido_en' => $v('fecha_tratamiento') ?? $fecha, 'sujetos' => [$sujeto],
                'observaciones' => mb_substr($aviso.' '.__('Tratamiento precaucional garrapaticida.').($v('observaciones') ? ' '.$v('observaciones') : ''), 0, 2000),
                'payload' => array_filter([
                    'producto_nombre' => $t($v('producto_comercial')), 'principio_activo' => $t($v('principio_activo')), 'tipo' => 'externo',
                    'dosis_aplicada' => $metodo ? self::ETIQUETAS[$metodo] : null,
                ], fn ($x) => $x !== null),
            ];
        }
        $mov = FamiliaMovimiento::movimientoConDte($est->id, $v('dte_numero'));

        return ['eventos' => $eventos, 'vincular' => $mov ? [$mov->id] : []];
    }

    public function metadatosDocumento(array $campos): array
    {
        $agente = Campos::valor($campos, 'agente_nombre');

        return array_filter([
            'emitido_en' => Campos::valor($campos, 'fecha_inspeccion'),
            'emisor' => ['nombre' => mb_substr('SENASA — FIDHA'.($agente ? ' ('.$agente.')' : ''), 0, 200), ...array_filter(['identificacion' => Campos::valor($campos, 'numero')])],
        ]);
    }
}
