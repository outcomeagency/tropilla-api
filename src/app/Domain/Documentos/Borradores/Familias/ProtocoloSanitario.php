<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Documentos\Borradores\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sanidad\Oficial\Actions\RegistrarProtocolo;
use App\Domain\Sanidad\Oficial\SanidadOficial;
use App\Domain\Sanidad\ResultadoPrueba;
use App\Exceptions\ApiException;
use App\Http\Requests\V1\Sanidad\ProtocoloRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Protocolo de laboratorio / planilla de sangrado / planilla de tuberculinización (TPA-66, senasa-planillas.md §5–6).
 * Esquema `protocolo_sanitario/1`: cabecera (enfermedad, prueba, motivo, laboratorio, protocolo, fechas, veterinario, RENSPA)
 * y una fila por animal (identificación, categoría, muestra, lecturas en mm, resultado). Confirmar registra el protocolo
 * (`transcripcion_revisada` con este documento como original) y sus `sanidad.prueba_diagnostica`. Identificaciones que no
 * coinciden con animales, totales que no cierran o lecturas que no dan el resultado escrito son **advertencias**: nada se corrige solo.
 */
final class ProtocoloSanitario implements FamiliaConRegistroPropio
{
    public const ESQUEMA = 'protocolo_sanitario/1';

    /** ruta => [grupo, etiqueta, tipo, opciones|null, critico] */
    private const CAMPOS = [
        'enfermedad' => ['protocolo', 'Enfermedad', 'opcion', null, true],
        'prueba' => ['protocolo', 'Prueba', 'opcion', null, true],
        'motivo' => ['protocolo', 'Motivo del muestreo', 'opcion', null, false],
        'modalidad' => ['protocolo', 'Modalidad', 'opcion', ['total', 'muestreo'], false],
        'protocolo_numero' => ['protocolo', 'N.º de protocolo', 'texto', null, false],
        'laboratorio' => ['protocolo', 'Laboratorio', 'texto', null, false],
        'fecha_muestreo' => ['protocolo', 'Fecha de muestreo / inoculación', 'fecha', null, true],
        'fecha_resultado' => ['protocolo', 'Fecha del resultado / lectura', 'fecha', null, false],
        'renspa' => ['protocolo', 'RENSPA', 'texto', null, false],
        'establecimiento' => ['protocolo', 'Establecimiento', 'texto', null, false],
        'titular' => ['protocolo', 'Titular', 'texto', null, false],
        'veterinario_nombre' => ['veterinario', 'Veterinario', 'texto', null, false],
        'veterinario_matricula' => ['veterinario', 'Matrícula / acreditación', 'texto', null, false],
        'firma_veterinario' => ['veterinario', 'Firma del veterinario', 'booleano', null, false],
        'total_muestras' => ['totales', 'Total de muestras', 'entero', null, false],
        'total_positivos' => ['totales', 'Total de positivos', 'entero', null, false],
        'observaciones' => ['totales', 'Observaciones', 'texto', null, false],
    ];

    /** columna => [etiqueta, tipo, opciones|null, critico] */
    private const ANIMAL = [
        'identificacion' => ['Identificación (caravana / RFID)', 'texto', null, true],
        'categoria' => ['Categoría (como figura)', 'texto', null, false],
        'muestra' => ['N.º de muestra / tubo', 'texto', null, false],
        'lectura_0h_mm' => ['Lectura 0 h (mm)', 'decimal', null, false],
        'lectura_72h_mm' => ['Lectura 72 h (mm)', 'decimal', null, false],
        'resultado' => ['Resultado', 'opcion', SanidadOficial::RESULTADOS, true],
    ];

    public function __construct(private readonly RegistrarProtocolo $registrarProtocolo) {}

    public function codigo(): string
    {
        return 'protocolo_sanitario';
    }

    public function tipoDocumento(): string
    {
        return 'analisis_laboratorio';
    }

    public function presentacion(): array
    {
        return [
            'esquemas' => [self::ESQUEMA => 'Protocolo de brucelosis / tuberculosis'],
            'grupos' => ['protocolo' => 'Datos del protocolo', 'veterinario' => 'Veterinario', 'totales' => 'Totales y observaciones', 'animales' => 'Animal :n'],
            'agregables' => [['grupo' => 'animales', 'etiqueta' => 'Agregar animal']],
        ];
    }

    public function bloqueantes(): array
    {
        return ['prueba_otra_enfermedad'];
    }

    public function completar(Establecimiento $est, array $campos): array
    {
        return $campos;
    }

    public function refrescarOpciones(string $establecimientoId, array $campos): array
    {
        return $campos;
    }

    /** El protocolo se registra en `registrar()` (FamiliaConRegistroPropio): no propone eventos sueltos. */
    public function confirmacion(Establecimiento $est, array $campos, string $documentoId): array
    {
        return ['eventos' => [], 'vincular' => []];
    }

    public function instrucciones(): string
    {
        $pruebas = DB::table('tipos_prueba')->whereNotNull('enfermedad')->orderBy('id')->get(['codigo', 'nombres'])
            ->map(fn ($t) => '"'.$t->codigo.'" ('.(json_decode($t->nombres, true)['es'] ?? $t->codigo).')')->implode(', ');

        return <<<TXT
        Es un documento sanitario de brucelosis o tuberculosis bovina/bubalina de Argentina. Puede ser:
        - "protocolo_laboratorio": protocolo o informe de resultados de un laboratorio de la red SENASA.
        - "planilla_sangrado": planilla de sangrado/muestreo del veterinario (animales, tubos, a veces resultado).
        - "planilla_tuberculinizacion": planilla de pruebas tuberculínicas con lecturas en mm a las 0 y 72 horas.
        - "desconocida": si no es ninguno de esos o no podés distinguirlo.
        Transcribí cada casillero tal como está escrito (fechas y números como figuran, sin reformatear). Para cada campo:
        valor (null si está vacío, tachado o ilegible), confianza entre 0 y 1 (manuscrito dudoso ≤ 0.6), pagina (1..n) y
        alternativas si un trazo admite otra lectura (p. ej. "1023" y "1028").
        enfermedad: "brucelosis" o "tuberculosis". prueba: uno de estos códigos según la técnica que figure: {$pruebas}.
        motivo: "does" (determinación de estatus), "recertificacion", "saneamiento", "movimiento" (precondición / certificado
        para mover), "rutina" u "otro". modalidad: "total" o "muestreo" si figura. firma_veterinario: "si" si se ve una firma, "no" si el lugar está vacío.
        animales: una fila por animal en el orden del documento: identificación (caravana, RFID o tatuaje como figura),
        categoría como figura, número de muestra o tubo, lecturas 0 h y 72 h en mm si es tuberculina, y resultado como figura
        ("negativo", "positivo", "sospechoso", "no concluyente", "no apta"). No inventes animales ni completes resultados vacíos.
        totales: total de muestras y de positivos si figuran.
        problemas: páginas cortadas, borrosas, con reflejo o sombra que impidan leer algo.
        TXT;
    }

    public function esquemaSalida(): array
    {
        $f = ['type' => 'object', 'additionalProperties' => false, 'required' => ['valor', 'confianza', 'pagina', 'alternativas'], 'properties' => [
            'valor' => ['type' => ['string', 'null']], 'confianza' => ['type' => 'number'], 'pagina' => ['type' => ['integer', 'null']],
            'alternativas' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]];
        $obj = fn (array $claves) => ['type' => 'object', 'additionalProperties' => false, 'required' => $claves, 'properties' => array_fill_keys($claves, $f)];
        $escalares = array_values(array_diff(array_keys(self::CAMPOS), ['total_muestras', 'total_positivos']));

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['variante', 'problemas', 'campos', 'animales', 'totales'], 'properties' => [
            'variante' => ['type' => 'string', 'enum' => ['protocolo_laboratorio', 'planilla_sangrado', 'planilla_tuberculinizacion', 'desconocida']],
            'problemas' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['pagina', 'descripcion'],
                'properties' => ['pagina' => ['type' => ['integer', 'null']], 'descripcion' => ['type' => 'string']]]],
            'campos' => $obj($escalares),
            'animales' => ['type' => 'array', 'items' => $obj(array_keys(self::ANIMAL))],
            'totales' => $obj(['muestras', 'positivos']),
        ]];
    }

    public function normalizar(array $salida): array
    {
        $campos = [];
        foreach (self::CAMPOS as $ruta => [$grupo, $etiqueta, $tipo, $opciones, $critico]) {
            $leido = str_starts_with($ruta, 'total_') ? ($salida['totales'][substr($ruta, 6)] ?? null) : ($salida['campos'][$ruta] ?? null);
            $campos[] = $this->campo($ruta, $grupo, $etiqueta, $tipo, $opciones ?? $this->opciones($ruta), $critico, $leido);
        }
        foreach (array_values((array) ($salida['animales'] ?? [])) as $i => $fila) {
            foreach (self::ANIMAL as $col => [$etiqueta, $tipo, $opciones, $critico]) {
                $campos[] = $this->campo("animales.{$i}.{$col}", 'animales', $etiqueta, $tipo, $opciones, $critico, $fila[$col] ?? null);
            }
        }

        return ['esquema' => self::ESQUEMA, 'campos' => $campos];
    }

    public function filaNueva(string $grupo, array $campos): array
    {
        if ($grupo !== 'animales') {
            throw ApiException::validacion(__('Solo se agregan filas de animales.'), ['grupo' => [__('Valor inválido.')]]);
        }
        $n = Campos::filas($campos, 'animales');
        $nuevos = [];
        foreach (self::ANIMAL as $col => [$etiqueta, $tipo, $opciones, $critico]) {
            $c = $this->campo("animales.{$n}.{$col}", 'animales', $etiqueta, $tipo, $opciones, $critico, null);
            $c['confianza'] = null;
            $nuevos[] = $c;
        }

        return $nuevos;
    }

    public function validarValor(array $campo, mixed $valor): mixed
    {
        if ($valor === null) {
            return null;
        }
        $error = fn (string $m) => throw ApiException::validacion($m, ["campos.{$campo['ruta']}" => [$m]]);

        return match ($campo['tipo']) {
            'fecha' => is_string($valor) && FamiliaBase::fecha($valor) === $valor ? $valor : $error(__('Fecha YYYY-MM-DD.')),
            'entero' => is_int($valor) && $valor >= 0 && $valor <= 1_000_000 ? $valor : $error(__('Entero entre 0 y 1.000.000.')),
            'decimal' => is_string($valor) && preg_match('/^(0|[1-9][0-9]{0,2})(\.[1-9])?$/', str_replace(',', '.', trim($valor)))
                ? str_replace(',', '.', trim($valor)) : $error(__('Milímetros con hasta un decimal (p. ej. 4.5).')),
            'booleano' => is_bool($valor) ? $valor : $error(__('Sí o no.')),
            'opcion' => is_string($valor) && in_array($valor, array_column($campo['opciones'] ?? [], 'valor'), true) ? $valor : $error(__('Opción inválida.')),
            default => is_string($valor) && mb_strlen(trim($valor)) <= 200 ? (trim($valor) === '' ? null : trim($valor)) : $error(__('Texto de hasta 200 caracteres.')),
        };
    }

    public function advertencias(Establecimiento $est, array $campos, array $salida): array
    {
        $v = fn (string $ruta) => Campos::valor($campos, $ruta);
        $adv = [];
        if (($salida['variante'] ?? null) === 'desconocida') {
            $adv[] = ['codigo' => 'formulario_no_reconocido', 'mensaje' => __('No parece un protocolo ni una planilla de brucelosis/TBC: revisá todo con cuidado.')];
        }
        foreach ((array) ($salida['problemas'] ?? []) as $p) {
            $adv[] = ['codigo' => 'calidad_imagen', 'mensaje' => (string) ($p['descripcion'] ?? __('Parte de la foto no se lee.')), 'detalle' => ['pagina' => $p['pagina'] ?? null]];
        }
        $fecha = $v('fecha_muestreo');
        if ($fecha === null) {
            $adv[] = ['codigo' => 'fecha_faltante', 'mensaje' => __('Falta la fecha de muestreo: sin fecha no se registra el protocolo.'), 'ruta' => 'fecha_muestreo'];
        } elseif ($fecha > now($est->zona_horaria)->toDateString()) {
            $adv[] = ['codigo' => 'fecha_futura', 'mensaje' => __('La fecha de muestreo es posterior a hoy.'), 'ruta' => 'fecha_muestreo'];
        }
        $prueba = $v('prueba');
        $enf = $v('enfermedad');
        $tipo = $prueba ? DB::table('tipos_prueba')->where('codigo', $prueba)->first() : null;
        if ($tipo !== null && $enf !== null && $tipo->enfermedad !== null && $tipo->enfermedad !== $enf) {
            $adv[] = ['codigo' => 'prueba_otra_enfermedad', 'mensaje' => __('La prueba elegida no es de :e: corregí una de las dos.', ['e' => $enf]), 'ruta' => 'prueba'];
        }
        $renspa = $v('renspa');
        $propio = DB::table('establecimientos')->where('id', $est->id)->value('renspa');
        if ($renspa !== null && $propio && preg_replace('/\D/', '', $propio) !== preg_replace('/\D/', '', $renspa)) {
            $adv[] = ['codigo' => 'renspa_distinto', 'mensaje' => __('El RENSPA del documento no coincide con el del establecimiento.'), 'ruta' => 'renspa',
                'detalle' => ['documento' => $renspa, 'establecimiento' => $propio]];
        }
        if ($v('firma_veterinario') === false) {
            $adv[] = ['codigo' => 'firma_no_detectada', 'mensaje' => __('No se detectó la firma del veterinario.'), 'ruta' => 'firma_veterinario'];
        }

        $n = Campos::filas($campos, 'animales');
        $positivos = 0;
        $filas = 0;
        $vistos = [];
        for ($i = 0; $i < $n; $i++) {
            $ident = $v("animales.{$i}.identificacion");
            $res = $v("animales.{$i}.resultado");
            if ($ident === null && $res === null) {
                continue; // fila vacía o descartada
            }
            $filas++;
            $positivos += $res === 'positivo' ? 1 : 0;
            if ($res === null) {
                $adv[] = ['codigo' => 'resultado_faltante', 'mensaje' => __('Fila :n: falta el resultado (si no se lee, marcá la fila como no aplica).', ['n' => $i + 1]), 'ruta' => "animales.{$i}.resultado"];
            }
            if ($ident === null) {
                $adv[] = ['codigo' => 'identificacion_faltante', 'mensaje' => __('Fila :n: falta la identificación del animal.', ['n' => $i + 1]), 'ruta' => "animales.{$i}.identificacion"];

                continue;
            }
            $animal = SanidadOficial::animalPorIdentificacion($est->id, $ident);
            if ($animal === null) {
                $adv[] = ['codigo' => 'animal_no_encontrado', 'mensaje' => __('Fila :n: «:id» no coincide con ningún animal de TROPILLA; queda en el protocolo sin pasar a una ficha.', ['n' => $i + 1, 'id' => $ident]),
                    'ruta' => "animales.{$i}.identificacion"];
            } elseif (isset($vistos[$animal])) {
                $adv[] = ['codigo' => 'identificacion_repetida', 'mensaje' => __('Fila :n: el animal ya figura en la fila :m.', ['n' => $i + 1, 'm' => $vistos[$animal] + 1]), 'ruta' => "animales.{$i}.identificacion"];
            } else {
                $vistos[$animal] = $i;
                $l0 = $v("animales.{$i}.lectura_0h_mm");
                $l72 = $v("animales.{$i}.lectura_72h_mm");
                if ($prueba !== null && $l0 !== null && $l72 !== null && $res !== null) {
                    $a = DB::table('animales_estado')->where('animal_id', $animal)->first(['especie_codigo', 'raza_codigo']);
                    $calc = ResultadoPrueba::calcular($prueba, $a->especie_codigo, $a->raza_codigo, $est->id, $l0, $l72);
                    if ($calc !== null && $calc['resultado'] !== ($res === 'no_apto' ? 'no_concluyente' : $res)) {
                        $adv[] = ['codigo' => 'resultado_distinto_al_calculado', 'mensaje' => __('Fila :n: con los umbrales vigentes (Δ :d mm) el resultado sería «:r».', ['n' => $i + 1, 'd' => $calc['diferencia_mm'], 'r' => $calc['resultado']]),
                            'ruta' => "animales.{$i}.resultado", 'detalle' => $calc];
                    }
                }
            }
        }
        if ($positivos > 0) {
            $adv[] = ['codigo' => 'marca_reactor', 'mensaje' => __(':n animales positivos quedan marcados como reactores (dato privado).', ['n' => $positivos])];
        }
        foreach (['total_muestras' => $filas, 'total_positivos' => $positivos] as $ruta => $suma) {
            $total = $v($ruta);
            if ($total !== null && $total !== $suma) {
                $adv[] = ['codigo' => 'totales_no_cierran', 'mensaje' => __('Las filas suman :s y el documento dice :t.', ['s' => $suma, 't' => $total]), 'ruta' => $ruta,
                    'detalle' => ['suma' => $suma, 'total' => $total]];
            }
        }

        return $adv;
    }

    public function registrar(Establecimiento $est, User $usuario, object $borrador, array $campos, bool $confirmarAdvertencias): array
    {
        $v = fn (string $ruta) => Campos::valor($campos, $ruta);
        $resultados = [];
        for ($i = 0, $n = Campos::filas($campos, 'animales'); $i < $n; $i++) {
            $fila = array_filter([
                'identificacion' => $v("animales.{$i}.identificacion"), 'muestra' => $v("animales.{$i}.muestra"),
                'resultado' => $v("animales.{$i}.resultado"), 'lectura_0h_mm' => $v("animales.{$i}.lectura_0h_mm"), 'lectura_72h_mm' => $v("animales.{$i}.lectura_72h_mm"),
                'observaciones' => $v("animales.{$i}.categoria") !== null ? mb_substr(__('Categoría en el original: :c', ['c' => $v("animales.{$i}.categoria")]), 0, 500) : null,
            ], fn ($x) => $x !== null);
            if (! isset($fila['identificacion']) && ! isset($fila['resultado'])) {
                continue;
            }
            $resultados[] = $fila;
        }
        $obs = trim(implode(' · ', array_filter([$v('observaciones'), $v('titular') ? __('Titular: :t', ['t' => $v('titular')]) : null])));
        $d = array_filter([
            'id' => (string) Str::uuid7(), 'enfermedad' => $v('enfermedad'), 'prueba_codigo' => $v('prueba'), 'motivo' => $v('motivo') ?? 'otro',
            'modalidad' => $v('modalidad'), 'fecha_muestreo' => $v('fecha_muestreo'), 'fecha_resultado' => $v('fecha_resultado'),
            'laboratorio' => $v('laboratorio'), 'protocolo_numero' => $v('protocolo_numero'), 'veterinario_nombre' => $v('veterinario_nombre'),
            'veterinario_matricula' => $v('veterinario_matricula'), 'origen' => 'transcripcion_revisada', 'documento_id' => $borrador->documento_id,
            'observaciones' => $obs !== '' ? mb_substr($obs, 0, 2000) : null, 'resultados' => $resultados,
        ], fn ($x) => $x !== null);
        $validado = Validator::make($d, ProtocoloRequest::reglas(), [], [])->validate();
        if (($validado['fecha_resultado'] ?? null) !== null && $validado['fecha_resultado'] < $validado['fecha_muestreo']) {
            unset($validado['fecha_resultado']); // advertido en la revisión; no se inventa otra fecha
        }
        [$p] = $this->registrarProtocolo->handle($est, $usuario, $validado, $confirmarAdvertencias, $borrador->id);

        return json_decode($p->evento_ids, true);
    }

    public function metadatosDocumento(array $campos): array
    {
        $emisor = Campos::valor($campos, 'laboratorio') ?? Campos::valor($campos, 'veterinario_nombre');

        return array_filter([
            'emitido_en' => Campos::valor($campos, 'fecha_resultado') ?? Campos::valor($campos, 'fecha_muestreo'),
            'emisor' => $emisor ? array_filter(['nombre' => mb_substr($emisor, 0, 200), 'identificacion' => Campos::valor($campos, 'veterinario_matricula')]) : null,
        ]);
    }

    /** @return list<array{valor: string, etiqueta: string}>|null */
    private function opciones(string $ruta): ?array
    {
        return match ($ruta) {
            'enfermedad' => [['valor' => 'brucelosis', 'etiqueta' => 'Brucelosis'], ['valor' => 'tuberculosis', 'etiqueta' => 'Tuberculosis']],
            'prueba' => DB::table('tipos_prueba')->whereNotNull('enfermedad')->orderBy('id')->get(['codigo', 'nombres'])
                ->map(fn ($t) => ['valor' => $t->codigo, 'etiqueta' => json_decode($t->nombres, true)['es'] ?? $t->codigo])->all(),
            'motivo' => array_map(fn ($m) => ['valor' => $m, 'etiqueta' => self::etiqueta($m)], SanidadOficial::MOTIVOS),
            default => null,
        };
    }

    private static function etiqueta(string $o): string
    {
        return match ($o) {
            'does' => 'DOES (determinación de estatus)', 'recertificacion' => 'Recertificación', 'saneamiento' => 'Saneamiento',
            'movimiento' => 'Precondición de movimiento', 'rutina' => 'Rutina', 'otro' => 'Otro', 'total' => 'Total', 'muestreo' => 'Muestreo',
            'negativo' => 'Negativo', 'sospechoso' => 'Sospechoso', 'positivo' => 'Positivo', 'no_concluyente' => 'No concluyente', 'no_apto' => 'Muestra no apta',
            default => $o,
        };
    }

    /** @param array<string, mixed>|null $leido {valor, confianza, pagina, alternativas} */
    private function campo(string $ruta, string $grupo, string $etiqueta, string $tipo, ?array $opciones, bool $critico, ?array $leido): array
    {
        $crudo = isset($leido['valor']) && is_string($leido['valor']) && trim($leido['valor']) !== '' ? trim($leido['valor']) : null;
        $opc = $opciones === null ? null : (is_string($opciones[0] ?? null) ? array_map(fn ($o) => ['valor' => $o, 'etiqueta' => self::etiqueta($o)], $opciones) : $opciones);
        $valor = $crudo === null ? null : $this->interpretar($ruta, $tipo, $opc, $crudo);
        $conf = isset($leido['confianza']) && is_numeric($leido['confianza']) ? round(max(0, min(1, (float) $leido['confianza'])), 2) : null;
        if ($crudo !== null && $valor === null) {
            $conf = min($conf ?? 0.3, 0.3);
        }

        return [
            'ruta' => $ruta, 'grupo' => $grupo, 'etiqueta' => $etiqueta, 'tipo' => $tipo, 'opciones' => $opc, 'critico' => $critico,
            'valor_extraido' => $crudo, 'valor' => $valor, 'confianza' => $conf,
            'pagina' => isset($leido['pagina']) && is_int($leido['pagina']) ? $leido['pagina'] : null,
            'alternativas' => array_values(array_filter((array) ($leido['alternativas'] ?? []), 'is_string')),
            'sugerido' => false, 'estado' => 'pendiente',
        ];
    }

    /** @param list<array{valor: string, etiqueta: string}>|null $opciones */
    private function interpretar(string $ruta, string $tipo, ?array $opciones, string $crudo): mixed
    {
        $norm = Str::lower(Str::ascii($crudo));

        return match ($tipo) {
            'fecha' => FamiliaBase::fecha($crudo),
            'entero' => preg_match('/^[\d.\s]+$/', $crudo) && preg_replace('/\D/', '', $crudo) !== '' ? (int) preg_replace('/\D/', '', $crudo) : null,
            'decimal' => preg_match('/^\s*(\d{1,3})(?:[.,](\d))?\s*(mm)?\s*$/i', $crudo, $m) ? ((string) (int) $m[1]).(isset($m[2]) && $m[2] !== '0' ? '.'.$m[2] : '') : null,
            'booleano' => match (true) {
                in_array($norm, ['si', 'true', 'x', 'firmado', 'firma'], true) => true,
                in_array($norm, ['no', 'false'], true) => false,
                default => null,
            },
            'opcion' => $this->opcion($ruta, $opciones ?? [], $norm),
            default => mb_substr($crudo, 0, 200),
        };
    }

    /** @param list<array{valor: string, etiqueta: string}> $opciones */
    private function opcion(string $ruta, array $opciones, string $norm): ?string
    {
        $valores = array_column($opciones, 'valor');
        if (in_array($norm, $valores, true)) {
            return $norm;
        }

        return match ($ruta) {
            'enfermedad' => match (true) {
                str_contains($norm, 'bruc') => 'brucelosis', str_contains($norm, 'tuber') || str_contains($norm, 'tbc') => 'tuberculosis', default => null,
            },
            'modalidad' => str_contains($norm, 'total') ? 'total' : (str_contains($norm, 'muestr') ? 'muestreo' : null),
            'motivo' => match (true) {
                str_contains($norm, 'does') || str_contains($norm, 'estatus') => 'does', str_contains($norm, 'recert') => 'recertificacion',
                str_contains($norm, 'sanea') => 'saneamiento', str_contains($norm, 'movim') || str_contains($norm, 'egreso') => 'movimiento',
                str_contains($norm, 'rutin') => 'rutina', default => null,
            },
            'prueba' => match (true) {
                str_contains($norm, 'bpa') => 'brucelosis_bpa', str_contains($norm, 'fpa') => 'brucelosis_fpa', str_contains($norm, '2-me') || str_contains($norm, '2me') || str_contains($norm, 'mercapto') => 'brucelosis_2me',
                str_contains($norm, 'sat') || str_contains($norm, 'lenta') => 'brucelosis_sat', str_contains($norm, 'elisa') => 'brucelosis_elisa',
                str_contains($norm, 'complemento') => 'brucelosis_fc', str_contains($norm, 'anocaudal') || str_contains($norm, 'ano-caudal') => 'tuberculina_anocaudal',
                str_contains($norm, 'comparada') => 'tuberculina_cervical_comparada', str_contains($norm, 'cervical') => 'tuberculina_cervical_simple',
                default => null,
            },
            default => match (true) { // resultado
                str_starts_with($norm, 'neg') || $norm === '-' => 'negativo', str_starts_with($norm, 'pos') || $norm === '+' => 'positivo',
                str_starts_with($norm, 'sosp') || $norm === '+/-' => 'sospechoso', str_contains($norm, 'no apt') || str_contains($norm, 'hemoli') => 'no_apto',
                str_contains($norm, 'concl') || $norm === 'nc' => 'no_concluyente', default => null,
            },
        };
    }
}
