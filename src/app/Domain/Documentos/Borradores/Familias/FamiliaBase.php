<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Documentos\Borradores\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Hacienda\Proyecciones\Identificaciones;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comportamiento común de las familias (TPA-62/64/65): forma del campo revisable, interpretación de lo leído sin
 * «corregirlo», validación de lo editado, categorías del catálogo, lotes y movimientos como opciones, y la salida
 * estructurada genérica (variante + problemas + campos escalares + filas repetidas). Una familia nueva declara sus
 * variantes (→ esquema versionado), sus campos y filas, y lo que propone al confirmar.
 */
abstract class FamiliaBase implements Familia
{
    /** Por debajo de esta confianza el campo es dudoso: no se acepta en bloque (RevisarCampos). */
    public const UMBRAL_DUDOSO = 0.6;

    /** Etiquetas de opciones (valor => etiqueta) que suma cada familia. */
    protected const ETIQUETAS = [];

    /** Palabras que también identifican una opción al interpretar lo leído (valor => [palabras sin acentos]). */
    protected const SINONIMOS = [];

    /** @return array<string, string> variante leída => esquema versionado (la primera es la vigente). */
    abstract protected function variantes(): array;

    /** @return array<string, array{0: string, 1: string, 2: string, 3: ?array, 4: bool}> ruta => [grupo, etiqueta, tipo, opciones, crítico] */
    abstract protected function escalares(string $variante): array;

    /** @return array<string, array<string, array{0: string, 1: string, 2: ?array, 3: bool}>> grupo => [columna => [etiqueta, tipo, opciones, crítico]] */
    protected function filasDef(): array
    {
        return [];
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: ?array, 4: bool}> campos de TROPILLA (no se leen del papel) */
    protected function propios(): array
    {
        return [];
    }

    /** Columnas de fila que no se leen del papel (las completa TROPILLA o la persona). */
    protected const COLUMNAS_PROPIAS = ['categoria_codigo', 'lote_id'];

    public function esquemaSalida(): array
    {
        $f = ['type' => 'object', 'additionalProperties' => false, 'required' => ['valor', 'confianza', 'pagina', 'alternativas'], 'properties' => [
            'valor' => ['type' => ['string', 'null']], 'confianza' => ['type' => 'number'], 'pagina' => ['type' => ['integer', 'null']],
            'alternativas' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]];
        $obj = fn (array $claves) => ['type' => 'object', 'additionalProperties' => false, 'required' => $claves, 'properties' => array_fill_keys($claves, $f)];
        $escalares = [];
        foreach (array_keys($this->variantes()) as $v) {
            $escalares = [...$escalares, ...array_keys($this->escalares($v))];
        }
        $props = [
            'variante' => ['type' => 'string', 'enum' => [...array_keys($this->variantes()), 'desconocida']],
            'problemas' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['pagina', 'descripcion'],
                'properties' => ['pagina' => ['type' => ['integer', 'null']], 'descripcion' => ['type' => 'string']]]],
            'campos' => $obj(array_values(array_unique($escalares))),
        ];
        foreach ($this->filasDef() as $grupo => $cols) {
            $props[$grupo] = ['type' => 'array', 'items' => $obj(array_values(array_diff(array_keys($cols), static::COLUMNAS_PROPIAS)))];
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props];
    }

    public function normalizar(array $salida): array
    {
        $variantes = $this->variantes();
        $variante = isset($variantes[$salida['variante'] ?? '']) ? $salida['variante'] : array_key_first($variantes);
        $campos = [];
        foreach ($this->escalares($variante) as $ruta => [$grupo, $etiqueta, $tipo, $opciones, $critico]) {
            $campos[] = $this->campo($ruta, $grupo, $etiqueta, $tipo, $opciones, $critico, $salida['campos'][$ruta] ?? null);
        }
        foreach ($this->filasDef() as $grupo => $cols) {
            foreach (array_values((array) ($salida[$grupo] ?? [])) as $i => $fila) {
                foreach ($cols as $col => [$etiqueta, $tipo, $opciones, $critico]) {
                    $campos[] = match ($col) {
                        'categoria_codigo' => $this->sugerenciaCategoria("{$grupo}.{$i}.categoria_codigo", $grupo, $fila),
                        default => $this->campo("{$grupo}.{$i}.{$col}", $grupo, $etiqueta, $tipo, $opciones, $critico, in_array($col, static::COLUMNAS_PROPIAS, true) ? null : ($fila[$col] ?? null)),
                    };
                }
            }
        }
        foreach ($this->propios() as $ruta => [$grupo, $etiqueta, $tipo, $opciones, $critico]) {
            $c = $this->campo($ruta, $grupo, $etiqueta, $tipo, $opciones, $critico, null);
            $c['confianza'] = null;
            $campos[] = $c;
        }

        return ['esquema' => $variantes[$variante], 'campos' => $campos];
    }

    public function completar(Establecimiento $est, array $campos): array
    {
        return $campos;
    }

    public function filaNueva(string $grupo, array $campos): array
    {
        $defs = $this->filasDef()[$grupo] ?? throw ApiException::validacion(__('Esa fila no se puede agregar en este documento.'), ['agregar_fila' => [__('Valor inválido.')]]);
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
            // Lote o movimiento de TROPILLA: la pertenencia al establecimiento se verifica en advertencias y al confirmar.
            'lote', 'evento' => is_string($valor) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($valor)) ? strtolower($valor) : $error(__('Elegí una opción de la lista.')),
            default => is_string($valor) && mb_strlen(trim($valor)) <= 500 ? (trim($valor) === '' ? null : trim($valor)) : $error(__('Texto de hasta 500 caracteres.')),
        };
    }

    public function bloqueantes(): array
    {
        return ['categoria_otra_especie'];
    }

    public function refrescarOpciones(string $establecimientoId, array $campos): array
    {
        $lotes = null;
        foreach ($campos as &$c) {
            if ($c['tipo'] === 'lote') {
                $c['opciones'] = $lotes ??= self::opcionesLote($establecimientoId);
            }
        }
        unset($c);

        return $campos;
    }

    public function presentacion(): array
    {
        $esquemas = [];
        foreach ($this->variantes() as $esquema) {
            $esquemas[$esquema] = $this->nombreEsquema($esquema);
        }
        $agregables = [];
        foreach (array_keys($this->filasDef()) as $grupo) {
            $agregables[] = ['grupo' => $grupo, 'etiqueta' => $this->etiquetaAgregar($grupo)];
        }

        return ['esquemas' => $esquemas, 'grupos' => $this->titulosGrupos(), 'agregables' => $agregables];
    }

    abstract protected function nombreEsquema(string $esquema): string;

    /** @return array<string, string> grupo => título visible ("Detalle :n" numera filas). */
    abstract protected function titulosGrupos(): array;

    protected function etiquetaAgregar(string $grupo): string
    {
        return __('Agregar fila');
    }

    // ── advertencias comunes ─────────────────────────────────────────────────────────────────────────────────────

    /** Formulario no reconocido y problemas de imagen informados por el modelo. */
    protected function advertenciasLectura(array $salida, string $queEs): array
    {
        $adv = [];
        if (($salida['variante'] ?? null) === 'desconocida') {
            $adv[] = ['codigo' => 'formulario_no_reconocido', 'mensaje' => __('No parece :x conocido: revisá todo con cuidado.', ['x' => $queEs])];
        }
        foreach ((array) ($salida['problemas'] ?? []) as $p) {
            $adv[] = ['codigo' => 'calidad_imagen', 'mensaje' => (string) ($p['descripcion'] ?? __('Parte de la foto no se lee.')), 'detalle' => ['pagina' => $p['pagina'] ?? null]];
        }

        return $adv;
    }

    protected function advertenciaFecha(?string $fecha, string $ruta, string $nombre, bool $obligatoria = true): ?array
    {
        if ($fecha === null) {
            return $obligatoria ? ['codigo' => 'fecha_faltante', 'mensaje' => __('Falta :n.', ['n' => $nombre]), 'ruta' => $ruta] : null;
        }

        return $fecha > now()->toDateString() ? ['codigo' => 'fecha_futura', 'mensaje' => __(':Nombre es posterior a hoy.', ['nombre' => $nombre]), 'ruta' => $ruta] : null;
    }

    public static function renspaValido(string $renspa): bool
    {
        return (bool) preg_match('/^\d{2}\.\d{3}\.\d\.\d{5}\/\d{2}$/', $renspa);
    }

    public static function mismoRenspa(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && preg_replace('/\D/', '', $a) !== '' && preg_replace('/\D/', '', $a) === preg_replace('/\D/', '', $b);
    }

    protected static function renspaPropio(Establecimiento $est): ?string
    {
        return DB::table('establecimientos')->where('id', $est->id)->value('renspa');
    }

    // ── sujetos (lote / caravanas) ───────────────────────────────────────────────────────────────────────────────

    /**
     * Caravanas, RFID o tatuajes escritos en el papel («123, 456 / 789») → ids de animales activos del establecimiento.
     *
     * @return array{ids: list<string>, desconocidas: list<string>}
     */
    public static function resolverCaravanas(Establecimiento $est, ?string $texto): array
    {
        $valores = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\/\n]+|\s{2,}/', (string) $texto) ?: []), fn ($v) => $v !== '')));
        if ($valores === []) {
            return ['ids' => [], 'desconocidas' => []];
        }
        $norm = fn (string $v) => Identificaciones::normalizar($v);
        $encontrados = DB::table('animal_identificaciones as i')->join('animales_estado as a', 'a.animal_id', '=', 'i.animal_id')
            ->where('a.establecimiento_id', $est->id)->where('a.estado', 'activo')->where('i.vigente', true)
            ->whereIn('i.valor_normalizado', array_map($norm, $valores))->get(['i.valor_normalizado', 'i.animal_id']);
        $porValor = [];
        foreach ($encontrados as $e) {
            $porValor[$e->valor_normalizado] = $e->animal_id;
        }
        $ids = [];
        $desconocidas = [];
        foreach ($valores as $v) {
            isset($porValor[$norm($v)]) ? $ids[] = $porValor[$norm($v)] : $desconocidas[] = $v;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return ['ids' => $ids, 'desconocidas' => $desconocidas];
    }

    protected static function lote(Establecimiento $est, ?string $loteId): ?object
    {
        return $loteId === null ? null : DB::table('lotes')->where('id', $loteId)->where('establecimiento_id', $est->id)->whereNull('archivado_en')->first();
    }

    /**
     * Sujetos de un registro sanitario de una fila (caravanas primero, si no el lote). Lanza regla_bloqueante si no hay.
     *
     * @return array<string, mixed>
     */
    protected function sujetoSanitario(Establecimiento $est, ?string $caravanas, ?string $loteId, string $donde): array
    {
        $r = self::resolverCaravanas($est, $caravanas);
        if ($r['desconocidas'] !== []) {
            throw ApiException::reglaBloqueante(__(':d: no hay animales activos con :c.', ['d' => $donde, 'c' => implode(', ', $r['desconocidas'])]));
        }
        if ($r['ids'] !== []) {
            return ['tipo' => 'animales', 'animal_ids' => $r['ids']];
        }
        if (self::lote($est, $loteId) !== null) {
            return ['tipo' => 'lote', 'lote_id' => $loteId];
        }
        throw ApiException::reglaBloqueante(__(':d: elegí el lote o anotá las caravanas de los animales.', ['d' => $donde]));
    }

    /** Advertencia (bloqueante al confirmar) por caravanas que no son de animales activos. */
    protected function advertenciaCaravanas(Establecimiento $est, ?string $caravanas, string $ruta, string $donde): ?array
    {
        $r = self::resolverCaravanas($est, $caravanas);

        return $r['desconocidas'] === [] ? null : ['codigo' => 'caravana_desconocida', 'ruta' => $ruta,
            'mensaje' => __(':d: no hay animales activos con :c.', ['d' => $donde, 'c' => implode(', ', $r['desconocidas'])]), 'detalle' => ['caravanas' => $r['desconocidas']]];
    }

    // ── catálogo ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<array{valor: string, etiqueta: string}> */
    public static function opcionesCategoria(): array
    {
        static $cache = null;

        return $cache ??= DB::table('categorias')->where('activa', true)->orderBy('especie_codigo')->orderBy('orden')->get(['codigo', 'especie_codigo', 'nombres'])
            ->map(fn ($c) => ['valor' => $c->codigo, 'etiqueta' => (json_decode($c->nombres, true)['es'] ?? $c->codigo).($c->especie_codigo === 'BUB' ? ' (búfalo)' : ' (bovino)')])->all();
    }

    /** @return list<array{valor: string, etiqueta: string}> lotes activos del establecimiento */
    public static function opcionesLote(string $establecimientoId): array
    {
        return DB::table('lotes as l')->leftJoin('lotes_estado as le', 'le.lote_id', '=', 'l.id')->where('l.establecimiento_id', $establecimientoId)->whereNull('l.archivado_en')
            ->orderBy('l.nombre')->get(['l.id', 'l.nombre', 'l.especie_codigo', 'l.anonimo', 'le.cabezas_anonimas'])
            ->map(fn ($l) => ['valor' => $l->id, 'etiqueta' => $l->nombre.' · '.($l->especie_codigo === 'BUB' ? 'búfalos' : 'bovinos')
                .($l->anonimo ? ' · '.(int) $l->cabezas_anonimas.' cab.' : '')])->all();
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

    // ── campo revisable ──────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed>|null $leido {valor, confianza, pagina, alternativas} */
    protected function campo(string $ruta, string $grupo, string $etiqueta, string $tipo, ?array $opciones, bool $critico, ?array $leido): array
    {
        $crudo = isset($leido['valor']) && is_string($leido['valor']) && trim($leido['valor']) !== '' ? trim($leido['valor']) : null;
        $valor = $crudo === null ? null : $this->interpretar($tipo, $opciones, $crudo);
        $conf = isset($leido['confianza']) && is_numeric($leido['confianza']) ? round(max(0, min(1, (float) $leido['confianza'])), 2) : null;
        if ($crudo !== null && $valor === null) {
            $conf = min($conf ?? 0.3, 0.3); // se leyó algo que no se pudo interpretar: revisar sí o sí
        }

        return [
            'ruta' => $ruta, 'grupo' => $grupo, 'etiqueta' => $etiqueta, 'tipo' => $tipo,
            'opciones' => $opciones === null ? null : (array_is_list($opciones) && is_string($opciones[0] ?? null) ? array_map(fn ($o) => ['valor' => $o, 'etiqueta' => $this->etiquetaOpcion($o)], $opciones) : $opciones),
            'critico' => $critico, 'valor_extraido' => $crudo, 'valor' => $valor, 'confianza' => $conf,
            'pagina' => isset($leido['pagina']) && is_int($leido['pagina']) ? $leido['pagina'] : null,
            'alternativas' => array_values(array_filter((array) ($leido['alternativas'] ?? []), 'is_string')),
            'sugerido' => false, 'estado' => 'pendiente',
        ];
    }

    /** Categoría del catálogo sugerida desde la fila leída (`categoria` + `especie`). Es sugerencia, no lectura. */
    protected function sugerenciaCategoria(string $ruta, string $grupo, array $fila): array
    {
        $c = $this->campo($ruta, $grupo, __('Categoría en TROPILLA'), 'categoria', self::opcionesCategoria(), true, null);
        $texto = is_string($fila['categoria']['valor'] ?? null) ? $fila['categoria']['valor'] : null;
        $especie = $this->interpretar('opcion', ['BOV', 'BUB'], (string) ($fila['especie']['valor'] ?? '')) ?? 'BOV';
        $codigo = $texto === null ? null : self::mapearCategoria($texto, $especie);
        if ($codigo !== null) {
            $c['valor'] = $codigo;
            $c['confianza'] = min(0.7, (float) ($fila['categoria']['confianza'] ?? 0.5));
            $c['sugerido'] = true;
        }

        return $c;
    }

    /** Marca un campo propio con un valor sugerido por TROPILLA (queda pendiente: la persona lo confirma). */
    protected static function sugerir(array &$campos, string $ruta, mixed $valor): void
    {
        foreach ($campos as &$c) {
            if ($c['ruta'] === $ruta && $c['estado'] === 'pendiente' && $c['valor'] === null && $valor !== null) {
                $c['valor'] = $valor;
                $c['sugerido'] = true;
            }
        }
        unset($c);
    }

    protected function interpretar(string $tipo, ?array $opciones, string $crudo): mixed
    {
        $norm = Str::lower(Str::ascii($crudo));

        return match ($tipo) {
            'fecha' => self::fecha($crudo),
            'entero' => preg_match('/^[\d.\s]+$/', $crudo) && preg_replace('/\D/', '', $crudo) !== '' ? (int) preg_replace('/\D/', '', $crudo) : null,
            'booleano' => match (true) {
                in_array($norm, ['si', 'true', 'x', 'firmado', 'firma'], true) => true,
                in_array($norm, ['no', 'false'], true) => false,
                default => null,
            },
            'opcion' => $this->interpretarOpcion($opciones ?? [], $norm),
            'texto' => mb_substr($crudo, 0, 500),
            default => null,
        };
    }

    private function interpretarOpcion(array $opciones, string $norm): ?string
    {
        if ($opciones === ['BOV', 'BUB']) {
            return match (true) {
                str_contains($norm, 'bub') || str_contains($norm, 'bufal') => 'BUB',
                str_contains($norm, 'bov') || str_contains($norm, 'vacun') => 'BOV',
                default => null,
            };
        }
        $valores = array_map(fn ($o) => is_array($o) ? $o['valor'] : $o, $opciones);
        foreach ($valores as $o) {
            foreach (static::SINONIMOS[$o] ?? [] as $palabra) {
                if (str_contains($norm, $palabra)) {
                    return $o;
                }
            }
        }

        return collect($valores)->first(fn ($o) => str_contains($norm, Str::substr(str_replace('_', ' ', $o), 0, 5)));
    }

    protected function etiquetaOpcion(string $o): string
    {
        return static::ETIQUETAS[$o] ?? match ($o) {
            'BOV' => 'Bovino', 'BUB' => 'Búfalo', default => Str::ucfirst(str_replace('_', ' ', $o)),
        };
    }
}
