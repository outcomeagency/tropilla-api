<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Comercial\Faena\Actions\RegistrarResultadoFaena;
use App\Domain\Comercial\Faena\Faena;
use App\Domain\Comercial\Faena\PlanillaFaena;
use App\Domain\Documentos\Borradores\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sanidad\Oficial\SanidadOficial;
use App\Exceptions\ApiException;
use App\Http\Requests\V1\Comercial\ResultadoFaenaRequest;
use App\Models\User;
use App\Support\Decimales as D;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Romaneo / resultado de faena del frigorífico en PDF o foto (TPA-67, senasa-planillas.md §11, Res. 40/2026). Esquema
 * `resultado_faena.romaneo/1`: frigorífico, fecha, tropa, romaneo, DT-e, cabezas, tipificador y una fila por res (garrón,
 * RFID/caravana, medias, peso, categoría, tipificación, decomiso). Confirmar registra el resultado (`transcripcion_revisada`
 * con este documento) sin crear eventos ni pesos vivos. Sumas que no cierran o RFID desconocidos son advertencias.
 */
final class ResultadoFaena extends FamiliaBase implements FamiliaConRegistroPropio
{
    public const ESQUEMA = 'resultado_faena.romaneo/1';

    protected const ETIQUETAS = [];

    public function __construct(private readonly RegistrarResultadoFaena $registrarResultado) {}

    public function codigo(): string
    {
        return 'resultado_faena';
    }

    public function tipoDocumento(): string
    {
        return 'resultado_faena';
    }

    protected function variantes(): array
    {
        return ['romaneo' => self::ESQUEMA];
    }

    protected function nombreEsquema(string $esquema): string
    {
        return 'Resultado de faena / romaneo';
    }

    protected function escalares(string $variante): array
    {
        return [
            'frigorifico' => ['tropa', 'Frigorífico', 'texto', null, true],
            'frigorifico_cuit' => ['tropa', 'CUIT del frigorífico', 'texto', null, false],
            'faenador_numero' => ['tropa', 'N.º de establecimiento faenador', 'texto', null, false],
            'fecha_faena' => ['tropa', 'Fecha de faena', 'fecha', null, true],
            'tropa_numero' => ['tropa', 'N.º de tropa', 'texto', null, true],
            'romaneo_numero' => ['tropa', 'N.º de romaneo', 'texto', null, false],
            'dte_numero' => ['tropa', 'N.º de DT-e', 'texto', null, false],
            'cabezas' => ['tropa', 'Cabezas de la tropa', 'entero', null, false],
            'tipificador' => ['tropa', 'Tipificador', 'texto', null, false],
            'total_kg' => ['totales', 'Total kg (como figura)', 'decimal', null, false],
        ];
    }

    protected function filasDef(): array
    {
        return ['reses' => [
            'garron' => ['Garrón', 'texto', null, true],
            'identificacion' => ['RFID / caravana', 'texto', null, false],
            'media_a_kg' => ['Media A (kg)', 'decimal', null, false],
            'media_b_kg' => ['Media B (kg)', 'decimal', null, false],
            'peso_kg' => ['Peso de la res (kg)', 'decimal', null, true],
            'categoria' => ['Categoría', 'texto', null, false],
            'tipificacion' => ['Tipificación', 'texto', null, false],
            'decomiso' => ['Decomiso', 'texto', null, false],
        ]];
    }

    /** `categoria` acá es la del romaneo (texto), no una columna propia de TROPILLA. */
    protected const COLUMNAS_PROPIAS = [];

    protected function titulosGrupos(): array
    {
        return ['tropa' => 'Datos de la tropa', 'totales' => 'Totales', 'reses' => 'Res :n'];
    }

    protected function etiquetaAgregar(string $grupo): string
    {
        return 'Agregar res';
    }

    public function instrucciones(): string
    {
        return <<<'TXT'
        Es un resultado de faena / romaneo de un frigorífico de Argentina (SIF, Res. 40/2026): una tropa de bovinos o búfalos
        faenada, con una fila por res. Variante "romaneo"; "desconocida" si no es un romaneo.
        Transcribí cada dato tal como figura (fechas y números como están, sin reformatear). Para cada campo: valor (null si
        está vacío o ilegible), confianza entre 0 y 1, pagina (1..n) y alternativas si un número admite otra lectura.
        campos: frigorífico (razón social), CUIT, número de establecimiento faenador, fecha de faena, número de tropa, número de
        romaneo, número de DT-e, cantidad de cabezas de la tropa, tipificador, y total_kg si el documento trae el total de kg.
        reses: una fila por res en el orden del documento: número de garrón (o correlativo), identificación RFID o caravana si
        figura, peso de la media A y de la media B si están separadas, peso de la res (si solo figuran las medias, null), categoría
        (novillo, vaquillona, vaca, toro, MEJ…), tipificación o clasificación (letras/grado de gordura), decomiso si hay.
        No inventes reses ni completes pesos vacíos. problemas: páginas cortadas, borrosas o con reflejo.
        TXT;
    }

    public function advertencias(Establecimiento $est, array $campos, array $salida): array
    {
        $v = fn (string $ruta) => Campos::valor($campos, $ruta);
        $adv = $this->advertenciasLectura($salida, __('un romaneo'));
        if ($a = $this->advertenciaFecha($v('fecha_faena'), 'fecha_faena', __('la fecha de faena'))) {
            $adv[] = $a;
        }
        $n = Campos::filas($campos, 'reses');
        $suma = '0';
        $filas = 0;
        $garrones = [];
        for ($i = 0; $i < $n; $i++) {
            $g = $v("reses.{$i}.garron");
            $a = $v("reses.{$i}.media_a_kg");
            $b = $v("reses.{$i}.media_b_kg");
            $p = $v("reses.{$i}.peso_kg") ?? ($a !== null && $b !== null ? Faena::sumar($a, $b) : null);
            if ($g === null && $p === null) {
                continue;
            }
            $filas++;
            if ($g === null) {
                $adv[] = ['codigo' => 'garron_faltante', 'mensaje' => __('Fila :n: falta el garrón.', ['n' => $i + 1]), 'ruta' => "reses.{$i}.garron"];
            } elseif (isset($garrones[$g])) {
                $adv[] = ['codigo' => 'garron_repetido', 'mensaje' => __('Fila :n: el garrón :g ya figura en la fila :m.', ['n' => $i + 1, 'g' => $g, 'm' => $garrones[$g] + 1]), 'ruta' => "reses.{$i}.garron"];
            } else {
                $garrones[$g] = $i;
            }
            if ($p === null) {
                $adv[] = ['codigo' => 'peso_faltante', 'mensaje' => __('Fila :n: falta el peso de la res.', ['n' => $i + 1]), 'ruta' => "reses.{$i}.peso_kg"];
            } else {
                $suma = D::sum($suma, $p);
            }
            if ($a !== null && $b !== null && $v("reses.{$i}.peso_kg") !== null && D::cmp(Faena::sumar($a, $b), $v("reses.{$i}.peso_kg")) !== 0) {
                $adv[] = ['codigo' => 'medias_no_suman', 'mensaje' => __('Fila :n: las medias no suman el peso de la res.', ['n' => $i + 1]), 'ruta' => "reses.{$i}.peso_kg"];
            }
            $id = $v("reses.{$i}.identificacion");
            if ($id !== null && SanidadOficial::animalPorIdentificacion($est->id, $id) === null) {
                $adv[] = ['codigo' => 'res_sin_asociar', 'mensaje' => __('Fila :n: «:id» no coincide con ningún animal de TROPILLA.', ['n' => $i + 1, 'id' => $id]), 'ruta' => "reses.{$i}.identificacion"];
            }
        }
        $total = $v('total_kg');
        if ($total !== null && D::cmp(Faena::kg($suma), $total) !== 0) {
            $adv[] = ['codigo' => 'totales_no_cierran', 'mensaje' => __('Las reses suman :s kg y el documento dice :t kg.', ['s' => Faena::kg($suma), 't' => $total]), 'ruta' => 'total_kg'];
        }
        $cab = $v('cabezas');
        if ($cab !== null && $cab !== $filas) {
            $adv[] = ['codigo' => 'cabezas_distintas', 'mensaje' => __('Hay :r reses y la tropa declara :c cabezas.', ['r' => $filas, 'c' => $cab]), 'ruta' => 'cabezas'];
        }

        return $adv;
    }

    public function bloqueantes(): array
    {
        return ['garron_faltante', 'garron_repetido', 'peso_faltante'];
    }

    public function confirmacion(Establecimiento $est, array $campos, string $documentoId): array
    {
        return ['eventos' => [], 'vincular' => []];
    }

    public function registrar(Establecimiento $est, User $usuario, object $borrador, array $campos, bool $confirmarAdvertencias): array
    {
        Gate::forUser($usuario)->authorize('comercial.precios', $est);
        $v = fn (string $ruta) => Campos::valor($campos, $ruta);
        $reses = [];
        for ($i = 0, $n = Campos::filas($campos, 'reses'); $i < $n; $i++) {
            $fila = array_filter([
                'garron' => $v("reses.{$i}.garron"), 'identificacion' => $v("reses.{$i}.identificacion"), 'media_a_kg' => $v("reses.{$i}.media_a_kg"),
                'media_b_kg' => $v("reses.{$i}.media_b_kg"), 'peso_kg' => $v("reses.{$i}.peso_kg"), 'categoria' => $v("reses.{$i}.categoria"),
                'tipificacion' => $v("reses.{$i}.tipificacion"), 'decomiso' => $v("reses.{$i}.decomiso"),
            ], fn ($x) => $x !== null);
            if (isset($fila['garron']) || isset($fila['peso_kg'])) {
                $reses[] = $fila;
            }
        }
        $d = array_filter([
            'id' => (string) Str::uuid7(), 'frigorifico' => $v('frigorifico'), 'frigorifico_cuit' => $v('frigorifico_cuit'), 'faenador_numero' => $v('faenador_numero'),
            'fecha_faena' => $v('fecha_faena'), 'tropa_numero' => $v('tropa_numero'), 'romaneo_numero' => $v('romaneo_numero'), 'dte_numero' => $v('dte_numero'),
            'cabezas_declaradas' => $v('cabezas'), 'tipificador' => $v('tipificador'), 'origen' => 'transcripcion_revisada', 'documento_id' => $borrador->documento_id,
            'reses' => $reses,
        ], fn ($x) => $x !== null);
        $validado = Validator::make($d, ResultadoFaenaRequest::reglas())->validate();
        $this->registrarResultado->handle($est, $usuario, $validado, $confirmarAdvertencias, $borrador->id);

        return []; // no crea eventos: el resultado queda en `resultados_faena` (borrador_id)
    }

    public function metadatosDocumento(array $campos): array
    {
        $f = Campos::valor($campos, 'frigorifico');

        return array_filter([
            'emitido_en' => Campos::valor($campos, 'fecha_faena'),
            'emisor' => $f ? array_filter(['nombre' => mb_substr($f, 0, 200), 'identificacion' => Campos::valor($campos, 'frigorifico_cuit')]) : null,
        ]);
    }

    public function validarValor(array $campo, mixed $valor): mixed
    {
        if ($campo['tipo'] === 'decimal' && $valor !== null) {
            $kg = is_string($valor) || is_int($valor) || is_float($valor) ? PlanillaFaena::kg((string) $valor) : null;

            return $kg ?? throw ApiException::validacion(__('Kilos con hasta un decimal (p. ej. 128,5).'), ["campos.{$campo['ruta']}" => [__('Peso inválido.')]]);
        }

        return parent::validarValor($campo, $valor);
    }

    protected function interpretar(string $tipo, ?array $opciones, string $crudo): mixed
    {
        return $tipo === 'decimal' ? PlanillaFaena::kg($crudo) : parent::interpretar($tipo, $opciones, $crudo);
    }
}
