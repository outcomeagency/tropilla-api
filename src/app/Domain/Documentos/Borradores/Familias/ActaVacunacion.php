<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Documentos\Borradores\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Acta de vacunación aftosa/brucelosis (TPA-62, senasa-planillas.md §4). Dos esquemas versionados:
 * - `acta_vacunacion.711_2025/1`: Acta de Vacunación Única (Res. SENASA 711/2025): sistemática/estratégica y total/parcial/cero.
 * - `acta_vacunacion.879_2002/1`: acta histórica en triplicado (Res. 879/2002): sin tipo de acto ni alcance.
 * El modelo identifica la variante; si no la reconoce se usa la vigente y se advierte. Cantidad vacunada > existencias,
 * totales que no cierran, RENSPA distinto o existencias distintas a TROPILLA son **advertencias**: nunca se corrige nada solo.
 */
final class ActaVacunacion implements Familia
{
    public const ESQUEMA_711 = 'acta_vacunacion.711_2025/1';

    public const ESQUEMA_879 = 'acta_vacunacion.879_2002/1';

    /** ruta => [grupo, etiqueta, tipo, opciones|null, critico, solo711] */
    private const CAMPOS = [
        'numero_acta' => ['acta', 'N.º de acta', 'texto', null, false, false],
        'tipo_acto' => ['acta', 'Tipo de acto', 'opcion', ['sistematica', 'estrategica'], false, true],
        'alcance' => ['acta', 'Alcance', 'opcion', ['total', 'parcial', 'cero'], true, true],
        'campana' => ['acta', 'Campaña', 'texto', null, false, false],
        'fecha' => ['acta', 'Fecha de vacunación', 'fecha', null, true, false],
        'renspa' => ['acta', 'RENSPA', 'texto', null, true, false],
        'establecimiento' => ['acta', 'Establecimiento', 'texto', null, false, false],
        'titular' => ['acta', 'Titular', 'texto', null, false, false],
        'cuit_titular' => ['acta', 'CUIT del titular', 'texto', null, false, false],
        'ente_sanitario' => ['vacunador', 'Ente sanitario', 'texto', null, false, false],
        'vacunador_nombre' => ['vacunador', 'Vacunador', 'texto', null, false, false],
        'vacunador_matricula' => ['vacunador', 'Matrícula / acreditación', 'texto', null, false, false],
        'responsable_nombre' => ['firmas', 'Productor o responsable', 'texto', null, false, false],
        'responsable_dni' => ['firmas', 'DNI del responsable', 'texto', null, false, false],
        'firma_responsable' => ['firmas', 'Firma del responsable', 'booleano', null, false, false],
        'firma_vacunador' => ['firmas', 'Firma del vacunador', 'booleano', null, false, false],
        'observaciones' => ['firmas', 'Observaciones', 'texto', null, false, false],
        'total_existentes' => ['totales', 'Total existente', 'entero', null, false, false],
        'total_vacunados' => ['totales', 'Total vacunado', 'entero', null, false, false],
    ];

    /** columna => [etiqueta, tipo, opciones|null, critico] */
    private const VACUNA = [
        'enfermedad' => ['Enfermedad', 'opcion', ['aftosa', 'brucelosis'], true],
        'producto' => ['Vacuna (marca)', 'texto', null, false],
        'serie' => ['Serie / lote', 'texto', null, false],
        'vencimiento' => ['Vencimiento', 'fecha', null, false],
    ];

    private const CATEGORIA = [
        'especie' => ['Especie', 'opcion', ['BOV', 'BUB'], true],
        'categoria' => ['Categoría (como figura)', 'texto', null, false],
        'categoria_codigo' => ['Categoría en TROPILLA', 'categoria', null, true],
        'existentes' => ['Existentes', 'entero', null, true],
        'vacunados_aftosa' => ['Vacunados aftosa', 'entero', null, true],
        'vacunados_brucelosis' => ['Vacunadas brucelosis', 'entero', null, false],
    ];

    public function codigo(): string
    {
        return 'acta_vacunacion';
    }

    public function tipoDocumento(): string
    {
        return 'vacunacion_aftosa';
    }

    public function instrucciones(): string
    {
        return <<<'TXT'
        Es un acta de vacunación antiaftosa (y a veces antibrucélica) de SENASA, Argentina. Puede ser:
        - "711_2025": Acta de Vacunación Única (Res. 711/2025): indica acto sistemático o estratégico y vacunación total, parcial o cero.
        - "879_2002": acta histórica en triplicado (Res. 879/2002).
        - "desconocida": si no es un acta de vacunación o no podés distinguirla.
        Transcribí cada casillero tal como está escrito (fechas y números como figuran, sin reformatear). Para cada campo:
        valor (null si está vacío, tachado o ilegible), confianza entre 0 y 1 (manuscrito dudoso ≤ 0.6), pagina (1..n) y
        alternativas si un trazo admite otra lectura (p. ej. "17" y "1/7"). tipo_acto: "sistematica" | "estrategica";
        alcance: "total" | "parcial" | "cero"; firmas: "si" si se ve una firma en el lugar, "no" si el lugar está vacío.
        vacunas: una fila por vacuna aplicada (enfermedad "aftosa" o "brucelosis", marca, serie/lote, vencimiento).
        categorias: una fila por categoría de la tabla (especie "bovino" o "bubalino", categoría como figura, existentes,
        vacunados contra aftosa y terneras vacunadas contra brucelosis). totales: la fila de totales si existe.
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
        $escalares = array_values(array_diff(array_keys(self::CAMPOS), ['total_existentes', 'total_vacunados']));

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['variante', 'problemas', 'campos', 'vacunas', 'categorias', 'totales'], 'properties' => [
            'variante' => ['type' => 'string', 'enum' => ['711_2025', '879_2002', 'desconocida']],
            'problemas' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['pagina', 'descripcion'],
                'properties' => ['pagina' => ['type' => ['integer', 'null']], 'descripcion' => ['type' => 'string']]]],
            'campos' => $obj($escalares),
            'vacunas' => ['type' => 'array', 'items' => $obj(['enfermedad', 'producto', 'serie', 'vencimiento'])],
            'categorias' => ['type' => 'array', 'items' => $obj(['especie', 'categoria', 'existentes', 'vacunados_aftosa', 'vacunados_brucelosis'])],
            'totales' => $obj(['existentes', 'vacunados']),
        ]];
    }

    public function normalizar(array $salida): array
    {
        $es879 = ($salida['variante'] ?? null) === '879_2002';
        $campos = [];
        foreach (self::CAMPOS as $ruta => [$grupo, $etiqueta, $tipo, $opciones, $critico, $solo711]) {
            if ($solo711 && $es879) {
                continue;
            }
            $leido = str_starts_with($ruta, 'total_') ? ($salida['totales'][substr($ruta, 6)] ?? null) : ($salida['campos'][$ruta] ?? null);
            $campos[] = $this->campo($ruta, $grupo, $etiqueta, $tipo, $opciones, $critico, $leido);
        }
        foreach (array_values((array) ($salida['vacunas'] ?? [])) as $i => $fila) {
            foreach (self::VACUNA as $col => [$etiqueta, $tipo, $opciones, $critico]) {
                $campos[] = $this->campo("vacunas.{$i}.{$col}", 'vacunas', $etiqueta, $tipo, $opciones, $critico, $fila[$col] ?? null);
            }
        }
        foreach (array_values((array) ($salida['categorias'] ?? [])) as $i => $fila) {
            foreach (self::CATEGORIA as $col => [$etiqueta, $tipo, $opciones, $critico]) {
                $campos[] = $col === 'categoria_codigo'
                    ? $this->sugerenciaCategoria($i, $fila)
                    : $this->campo("categorias.{$i}.{$col}", 'categorias', $etiqueta, $tipo, $opciones, $critico, $fila[$col] ?? null);
            }
        }

        return ['esquema' => $es879 ? self::ESQUEMA_879 : self::ESQUEMA_711, 'campos' => $campos];
    }

    public function filaNueva(string $grupo, array $campos): array
    {
        $defs = match ($grupo) {
            'vacunas' => self::VACUNA,
            'categorias' => self::CATEGORIA,
            default => throw ApiException::validacion(__('Solo se agregan filas de vacunas o categorías.'), ['grupo' => [__('Valor inválido.')]]),
        };
        $n = Campos::filas($campos, $grupo);
        $nuevos = [];
        foreach ($defs as $col => [$etiqueta, $tipo, $opciones, $critico]) {
            $c = $this->campo("{$grupo}.{$n}.{$col}", $grupo, $etiqueta, $tipo, $col === 'categoria_codigo' ? self::opcionesCategoria() : $opciones, $critico, null);
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
            'fecha' => is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && checkdate((int) substr($valor, 5, 2), (int) substr($valor, 8, 2), (int) substr($valor, 0, 4))
                ? $valor : $error(__('Fecha YYYY-MM-DD.')),
            'entero' => is_int($valor) && $valor >= 0 && $valor <= 1_000_000 ? $valor : $error(__('Entero entre 0 y 1.000.000.')),
            'booleano' => is_bool($valor) ? $valor : $error(__('Sí o no.')),
            'opcion', 'categoria' => is_string($valor) && in_array($valor, array_column($campo['opciones'] ?? [], 'valor'), true) ? $valor : $error(__('Opción inválida.')),
            default => is_string($valor) && mb_strlen(trim($valor)) <= 200 ? (trim($valor) === '' ? null : trim($valor)) : $error(__('Texto de hasta 200 caracteres.')),
        };
    }

    public function advertencias(Establecimiento $est, array $campos, array $salida): array
    {
        $v = fn (string $ruta) => Campos::valor($campos, $ruta);
        $adv = [];
        if (($salida['variante'] ?? null) === 'desconocida') {
            $adv[] = ['codigo' => 'formulario_no_reconocido', 'mensaje' => __('No parece un acta de vacunación conocida: revisá todo con cuidado.')];
        }
        foreach ((array) ($salida['problemas'] ?? []) as $p) {
            $adv[] = ['codigo' => 'calidad_imagen', 'mensaje' => (string) ($p['descripcion'] ?? __('Parte de la foto no se lee.')), 'detalle' => ['pagina' => $p['pagina'] ?? null]];
        }

        $fecha = $v('fecha');
        if ($fecha === null) {
            $adv[] = ['codigo' => 'fecha_faltante', 'mensaje' => __('Falta la fecha de vacunación: sin fecha no se registra el evento.'), 'ruta' => 'fecha'];
        } elseif ($fecha > now()->toDateString()) {
            $adv[] = ['codigo' => 'fecha_futura', 'mensaje' => __('La fecha del acta es posterior a hoy.'), 'ruta' => 'fecha'];
        }
        $renspa = $v('renspa');
        if ($renspa !== null) {
            if (! preg_match('/^\d{2}\.\d{3}\.\d\.\d{5}\/\d{2}$/', $renspa)) {
                $adv[] = ['codigo' => 'renspa_formato', 'mensaje' => __('El RENSPA no tiene el formato 00.000.0.00000/00.'), 'ruta' => 'renspa'];
            }
            $propio = DB::table('establecimientos')->where('id', $est->id)->value('renspa');
            if ($propio && preg_replace('/\D/', '', $propio) !== preg_replace('/\D/', '', $renspa)) {
                $adv[] = ['codigo' => 'renspa_distinto', 'mensaje' => __('El RENSPA del acta no coincide con el del establecimiento.'), 'ruta' => 'renspa',
                    'detalle' => ['acta' => $renspa, 'establecimiento' => $propio]];
            }
        }
        foreach (['firma_responsable' => __('No se detectó la firma del responsable.'), 'firma_vacunador' => __('No se detectó la firma del vacunador.')] as $ruta => $msg) {
            if ($v($ruta) === false) {
                $adv[] = ['codigo' => 'firma_no_detectada', 'mensaje' => $msg, 'ruta' => $ruta];
            }
        }

        // Cantidades: vacunado ≤ existente y totales, solo como advertencia (no se autocorrige).
        $sumaExist = 0;
        $sumaVac = 0;
        $existencias = DB::table('existencias')->where('establecimiento_id', $est->id)->pluck('cabezas', 'categoria_codigo')->all();
        for ($i = 0, $n = Campos::filas($campos, 'categorias'); $i < $n; $i++) {
            $exist = $v("categorias.{$i}.existentes");
            $sumaExist += (int) $exist;
            foreach (['vacunados_aftosa', 'vacunados_brucelosis'] as $col) {
                $vac = $v("categorias.{$i}.{$col}");
                if ($col === 'vacunados_aftosa') {
                    $sumaVac += (int) $vac;
                }
                if ($vac !== null && $exist !== null && $vac > $exist) {
                    $adv[] = ['codigo' => 'vacunados_mayor_existentes', 'mensaje' => __('Fila :n: hay más vacunados que existentes.', ['n' => $i + 1]),
                        'ruta' => "categorias.{$i}.{$col}", 'detalle' => ['existentes' => $exist, 'vacunados' => $vac]];
                }
            }
            $cat = $v("categorias.{$i}.categoria_codigo");
            if ($cat !== null && $exist !== null && (int) ($existencias[$cat] ?? 0) !== $exist) {
                $adv[] = ['codigo' => 'existencias_distintas', 'mensaje' => __('Fila :n: el acta dice :a y TROPILLA tiene :t cabezas de esa categoría.', ['n' => $i + 1, 'a' => $exist, 't' => (int) ($existencias[$cat] ?? 0)]),
                    'ruta' => "categorias.{$i}.existentes", 'detalle' => ['acta' => $exist, 'tropilla' => (int) ($existencias[$cat] ?? 0), 'categoria' => $cat]];
            }
            $esp = $v("categorias.{$i}.especie");
            if ($cat !== null && $esp !== null && ! str_starts_with($cat, $esp.'_')) {
                $adv[] = ['codigo' => 'categoria_otra_especie', 'mensaje' => __('Fila :n: la categoría elegida es de otra especie.', ['n' => $i + 1]), 'ruta' => "categorias.{$i}.categoria_codigo"];
            }
        }
        foreach (['total_existentes' => $sumaExist, 'total_vacunados' => $sumaVac] as $ruta => $suma) {
            $total = $v($ruta);
            if ($total !== null && $total !== $suma) {
                $adv[] = ['codigo' => 'totales_no_cierran', 'mensaje' => __('La suma de las filas (:s) no coincide con el total del acta (:t).', ['s' => $suma, 't' => $total]),
                    'ruta' => $ruta, 'detalle' => ['suma' => $suma, 'total' => $total]];
            }
        }

        return $adv;
    }

    public function eventos(array $campos): array
    {
        $v = fn (string $ruta) => Campos::valor($campos, $ruta);
        $fecha = $v('fecha');
        if ($fecha === null || $v('alcance') === 'cero') {
            return [];
        }
        $vacunador = trim(implode(' ', array_filter([$v('vacunador_nombre'), $v('vacunador_matricula') ? '(Mat. '.$v('vacunador_matricula').')' : null])));
        $acta = $v('numero_acta');
        $obs = mb_substr(__('Transcripción asistida del acta de vacunación:n, revisada campo por campo. No es una presentación oficial.',
            ['n' => $acta ? ' N.º '.$acta : '']), 0, 2000);

        $eventos = [];
        for ($j = 0, $n = Campos::filas($campos, 'vacunas'); $j < $n; $j++) {
            $enf = $v("vacunas.{$j}.enfermedad");
            if ($enf === null) {
                continue;
            }
            $col = $enf === 'brucelosis' ? 'vacunados_brucelosis' : 'vacunados_aftosa';
            $cats = [];
            for ($i = 0, $m = Campos::filas($campos, 'categorias'); $i < $m; $i++) {
                if (($v("categorias.{$i}.{$col}") ?? 0) > 0 && ($c = $v("categorias.{$i}.categoria_codigo")) !== null) {
                    $cats[] = $c;
                }
            }
            if ($cats === []) {
                continue;
            }
            $payload = array_filter([
                'producto_nombre' => $v("vacunas.{$j}.producto") ?? ($enf === 'brucelosis' ? 'Vacuna antibrucélica' : 'Vacuna antiaftosa'),
                'enfermedad' => $enf,
                'numero_dosis' => $enf === 'brucelosis' ? 'unica' : null,
                'envase_lote' => $v("vacunas.{$j}.serie"),
                'envase_vence' => $v("vacunas.{$j}.vencimiento"),
                'vacunador' => $vacunador !== '' ? mb_substr($vacunador, 0, 200) : null,
                'campana' => $v('campana') !== null ? mb_substr((string) $v('campana'), 0, 200) : null,
            ], fn ($x) => $x !== null);
            $eventos[] = ['tipo' => 'sanidad.vacunacion', 'ocurrido_en' => $fecha, 'payload' => $payload, 'categorias' => array_values(array_unique($cats)), 'observaciones' => $obs];
        }

        return $eventos;
    }

    public function metadatosDocumento(array $campos): array
    {
        $ente = Campos::valor($campos, 'ente_sanitario') ?? Campos::valor($campos, 'vacunador_nombre');

        return array_filter([
            'emitido_en' => Campos::valor($campos, 'fecha'),
            'emisor' => $ente ? array_filter(['nombre' => mb_substr($ente, 0, 200), 'identificacion' => Campos::valor($campos, 'vacunador_matricula')]) : null,
        ]);
    }

    /** @return list<array{valor: string, etiqueta: string}> */
    public static function opcionesCategoria(): array
    {
        static $cache = null;

        return $cache ??= DB::table('categorias')->where('activa', true)->orderBy('especie_codigo')->orderBy('orden')->get(['codigo', 'especie_codigo', 'nombres'])
            ->map(fn ($c) => ['valor' => $c->codigo, 'etiqueta' => (json_decode($c->nombres, true)['es'] ?? $c->codigo).($c->especie_codigo === 'BUB' ? ' (búfalo)' : ' (bovino)')])->all();
    }

    /** @param array<string, mixed>|null $leido {valor, confianza, pagina, alternativas} */
    private function campo(string $ruta, string $grupo, string $etiqueta, string $tipo, ?array $opciones, bool $critico, ?array $leido): array
    {
        $crudo = isset($leido['valor']) && is_string($leido['valor']) && trim($leido['valor']) !== '' ? trim($leido['valor']) : null;
        $valor = $crudo === null ? null : $this->interpretar($tipo, $opciones, $crudo);
        $conf = isset($leido['confianza']) && is_numeric($leido['confianza']) ? round(max(0, min(1, (float) $leido['confianza'])), 2) : null;
        if ($crudo !== null && $valor === null) {
            $conf = min($conf ?? 0.3, 0.3); // se leyó algo que no se pudo interpretar: revisar sí o sí
        }

        return [
            'ruta' => $ruta, 'grupo' => $grupo, 'etiqueta' => $etiqueta, 'tipo' => $tipo,
            'opciones' => $opciones === null ? null : (array_is_list($opciones) && is_string($opciones[0] ?? null) ? array_map(fn ($o) => ['valor' => $o, 'etiqueta' => self::etiquetaOpcion($o)], $opciones) : $opciones),
            'critico' => $critico, 'valor_extraido' => $crudo, 'valor' => $valor, 'confianza' => $conf,
            'pagina' => isset($leido['pagina']) && is_int($leido['pagina']) ? $leido['pagina'] : null,
            'alternativas' => array_values(array_filter((array) ($leido['alternativas'] ?? []), 'is_string')),
            'sugerido' => false, 'estado' => 'pendiente',
        ];
    }

    private function sugerenciaCategoria(int $i, array $fila): array
    {
        $c = $this->campo("categorias.{$i}.categoria_codigo", 'categorias', self::CATEGORIA['categoria_codigo'][0], 'categoria', self::opcionesCategoria(), true, null);
        $texto = is_string($fila['categoria']['valor'] ?? null) ? $fila['categoria']['valor'] : null;
        $especie = $this->interpretar('opcion', ['BOV', 'BUB'], (string) ($fila['especie']['valor'] ?? '')) ?? 'BOV';
        $codigo = $texto === null ? null : self::mapearCategoria($texto, $especie);
        if ($codigo !== null) {
            // Sugerencia de TROPILLA, no lectura: confianza acotada y marcada como sugerida.
            $c['valor'] = $codigo;
            $c['confianza'] = min(0.7, (float) ($fila['categoria']['confianza'] ?? 0.5));
            $c['sugerido'] = true;
        }

        return $c;
    }

    /** «Vacas» → BOV_VACA, «Terneras» → BOV_TERNERA (singular, sin acentos, contra el catálogo). */
    public static function mapearCategoria(string $texto, string $especie): ?string
    {
        $norm = fn (string $s) => preg_replace('/[^a-z ]/', '', Str::lower(Str::ascii($s)));
        $singular = function (string $s): string {
            $s = trim($s);

            return match (true) {
                str_ends_with($s, 'yes') => substr($s, 0, -2),
                str_ends_with($s, 'res'), str_ends_with($s, 'les'), str_ends_with($s, 'nes') => substr($s, 0, -2),
                str_ends_with($s, 's') => substr($s, 0, -1),
                default => $s,
            };
        };
        $buscado = $singular($norm($texto));
        foreach (self::opcionesCategoria() as $o) {
            if (! str_starts_with($o['valor'], $especie.'_')) {
                continue;
            }
            $nombre = $norm(explode('/', explode('(', $o['etiqueta'])[0])[0]);
            if ($buscado !== '' && $buscado === trim($nombre)) {
                return $o['valor'];
            }
        }

        return null;
    }

    private function interpretar(string $tipo, ?array $opciones, string $crudo): mixed
    {
        $norm = Str::lower(Str::ascii($crudo));

        return match ($tipo) {
            'fecha' => self::fecha($crudo),
            'entero' => preg_match('/^[\d.\s]+$/', $crudo) && preg_replace('/\D/', '', $crudo) !== '' ? (int) preg_replace('/\D/', '', $crudo) : null,
            'booleano' => match (true) {
                in_array($norm, ['si', 'sí', 'true', 'x', 'firmado', 'firma'], true) => true,
                in_array($norm, ['no', 'false'], true) => false,
                default => null,
            },
            'opcion' => match (true) {
                $opciones === ['BOV', 'BUB'] => match (true) {
                    str_contains($norm, 'bub') || str_contains($norm, 'bufal') => 'BUB',
                    str_contains($norm, 'bov') || str_contains($norm, 'vacun') => 'BOV',
                    default => null,
                },
                default => collect($opciones)->first(fn ($o) => str_contains($norm, Str::substr($o, 0, 5))),
            },
            'texto' => mb_substr($crudo, 0, 200),
            default => null,
        };
    }

    /** dd/mm/aaaa, dd-mm-aa, aaaa-mm-dd → aaaa-mm-dd (null si no es una fecha válida). */
    public static function fecha(string $s): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', trim($s), $m)) {
            [$a, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{2}|\d{4})$/', trim($s), $m)) {
            [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $a = $a < 100 ? 2000 + $a : $a;
        } else {
            return null;
        }

        return checkdate($mes, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $mes, $d) : null;
    }

    private static function etiquetaOpcion(string $o): string
    {
        return match ($o) {
            'sistematica' => 'Sistemática', 'estrategica' => 'Estratégica', 'total' => 'Total', 'parcial' => 'Parcial', 'cero' => 'Cero',
            'aftosa' => 'Aftosa', 'brucelosis' => 'Brucelosis', 'BOV' => 'Bovino', 'BUB' => 'Búfalo', default => $o,
        };
    }
}
