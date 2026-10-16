<?php

namespace App\Domain\Hacienda\Importacion;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Hacienda\Proyecciones\Identificaciones;
use Illuminate\Support\Facades\DB;

/**
 * Valida y normaliza una fila de la planilla de stock → `animal_nuevo` listo para el evento `hacienda.alta`.
 * Categoría y raza aceptan código o nombre (catálogo de la especie); lote y potrero por nombre del establecimiento.
 */
final class ValidadorFila
{
    public const COLUMNAS = ['especie', 'sexo', 'categoria', 'raza', 'caravana', 'caravana_oficial', 'rfid', 'tatuaje', 'marca',
        'fecha_nacimiento', 'nacimiento_estimado', 'castrado', 'denticion', 'lote', 'potrero', 'senas'];

    /** @var array<string, int> identificaciones vistas en el archivo (tipo|valor → fila) */
    private array $vistas = [];

    public function __construct(private readonly string $establecimientoId, private readonly ?string $especieDefault) {}

    /**
     * @param  array<string, string>  $fila
     * @return array{datos: array<string, mixed>, errores: list<array{campo: string, mensaje: string}>, advertencias: list<array{codigo: string, mensaje: string}>}
     */
    public function validar(array $fila, int $numero): array
    {
        $e = [];
        $adv = [];
        $err = function (string $campo, string $mensaje) use (&$e) {
            $e[] = ['campo' => $campo, 'mensaje' => $mensaje];
        };
        $si = fn ($v) => in_array(mb_strtolower((string) $v), ['si', 'sí', 's', 'x', '1', 'true', 'yes'], true);

        $especie = strtoupper($fila['especie'] ?? '') ?: $this->especieDefault;
        $especie = match (mb_strtolower((string) $especie)) {
            'bovino', 'bov', 'vacuno' => 'BOV', 'bubalino', 'bub', 'bufalo', 'búfalo' => 'BUB', default => $especie,
        };
        if (! $especie || ! DB::table('especies')->where('codigo', $especie)->exists()) {
            $err('especie', 'Especie desconocida (BOV o BUB).');
        }
        $sexo = match (mb_strtolower($fila['sexo'] ?? '')) {
            'm', 'macho' => 'M', 'h', 'hembra' => 'H', default => null,
        };
        if ($sexo === null) {
            $err('sexo', 'Sexo: M (macho) o H (hembra).');
        }

        $categoria = $this->catalogo('categorias', $fila['categoria'] ?? '', $especie);
        if ($categoria === null) {
            $err('categoria', 'Categoría desconocida para la especie.');
        } elseif ($sexo && ($cs = DB::table('categorias')->where('codigo', $categoria)->value('sexo')) && $cs !== $sexo) {
            $err('categoria', 'La categoría no corresponde al sexo.');
        }
        $raza = null;
        if (($fila['raza'] ?? '') !== '') {
            $raza = $this->catalogo('razas', $fila['raza'], $especie);
            if ($raza === null) {
                $err('raza', 'Raza desconocida para la especie.');
            }
        }

        $fecha = null;
        if (($fila['fecha_nacimiento'] ?? '') !== '') {
            $f = $fila['fecha_nacimiento'];
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $f, $m)) {
                $f = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || ! checkdate((int) substr($f, 5, 2), (int) substr($f, 8, 2), (int) substr($f, 0, 4))) {
                $err('fecha_nacimiento', 'Fecha inválida (AAAA-MM-DD o DD/MM/AAAA).');
            } elseif ($f > now()->toDateString()) {
                $err('fecha_nacimiento', 'La fecha de nacimiento es futura.');
            } else {
                $fecha = $f;
            }
        }
        $denticion = $fila['denticion'] ?? '';
        $denticion = match (mb_strtolower($denticion)) {
            '' => null, 'leche', 'dl' => 'leche', '2', '2d' => '2', '4', '4d' => '4', '6', '6+', '6_mas', 'boca llena', 'bl' => '6_mas', default => 'x',
        };
        if ($denticion === 'x') {
            $err('denticion', 'Dentición: leche, 2, 4 o 6+.');
            $denticion = null;
        }

        $idents = [];
        foreach (['caravana' => 'caravana_visual', 'caravana_oficial' => 'caravana_oficial', 'rfid' => 'rfid', 'tatuaje' => 'tatuaje', 'marca' => 'marca_senal'] as $col => $tipo) {
            $valor = $fila[$col] ?? '';
            if ($valor === '') {
                continue;
            }
            $norm = Identificaciones::normalizar($valor);
            if ($tipo === 'rfid' && ! preg_match('/^\d{15}$/', $norm)) {
                $err($col, 'El RFID tiene 15 dígitos.');
            }
            if (isset($this->vistas["{$tipo}|{$norm}"])) {
                $err($col, "Repetida en la fila {$this->vistas["{$tipo}|{$norm}"]}.");
            } elseif (DB::table('animal_identificaciones')->where('establecimiento_id', $this->establecimientoId)->where('tipo', $tipo)
                ->where('valor_normalizado', $norm)->where('vigente', true)->exists()) {
                $err($col, 'Ya está vigente en otro animal del establecimiento.');
            }
            $this->vistas["{$tipo}|{$norm}"] = $numero;
            $idents[] = ['tipo' => $tipo, 'valor' => $valor];
            if ($tipo === 'tatuaje' && $raza && ($pref = DB::table('razas')->where('codigo', $raza)->value('prefijo_tatuaje')) && ! str_starts_with(strtoupper($valor), $pref)) {
                $adv[] = ['codigo' => 'tatuaje_prefijo', 'mensaje' => "El tatuaje de esta raza empieza con {$pref}."];
            }
        }
        if ($idents === []) {
            $adv[] = ['codigo' => 'sin_identificacion', 'mensaje' => 'El animal entra sin identificación.'];
        }

        $loteId = null;
        if (($fila['lote'] ?? '') !== '') {
            $lote = DB::table('lotes')->where('establecimiento_id', $this->establecimientoId)->whereNull('archivado_en')
                ->whereRaw('lower(nombre) = lower(?)', [$fila['lote']])->first();
            if ($lote === null) {
                $err('lote', 'No existe un lote con ese nombre.');
            } elseif ($especie && $lote->especie_codigo !== $especie) {
                $err('lote', 'El lote es de otra especie.');
            } else {
                $loteId = $lote->id;
            }
        }
        $potreroId = null;
        if (($fila['potrero'] ?? '') !== '') {
            $potreroId = DB::table('potreros')->where('establecimiento_id', $this->establecimientoId)->whereNull('archivado_en')
                ->whereRaw('lower(nombre) = lower(?)', [$fila['potrero']])->value('id');
            if ($potreroId === null) {
                $err('potrero', 'No existe un potrero con ese nombre.');
            }
        }

        $datos = array_filter([
            'especie' => $especie, 'sexo' => $sexo, 'categoria' => $categoria, 'raza' => $raza, 'fecha_nacimiento' => $fecha,
            'nacimiento_estimado' => ($fila['nacimiento_estimado'] ?? '') !== '' ? $si($fila['nacimiento_estimado']) : null,
            'castrado' => ($fila['castrado'] ?? '') !== '' ? $si($fila['castrado']) : null,
            'denticion' => $denticion, 'identificaciones' => $idents ?: null, 'lote_id' => $loteId, 'potrero_id' => $potreroId,
            'senas' => ($fila['senas'] ?? '') !== '' ? mb_substr($fila['senas'], 0, 2000) : null,
        ], fn ($v) => $v !== null);

        return ['datos' => $datos, 'errores' => $e, 'advertencias' => $adv];
    }

    private function catalogo(string $tabla, string $valor, ?string $especie): ?string
    {
        if ($valor === '' || $especie === null) {
            return null;
        }
        $q = DB::table($tabla)->where('especie_codigo', $especie);
        if ($tabla === 'razas') {
            $q->where(fn ($w) => $w->whereNull('establecimiento_id')->orWhere('establecimiento_id', $this->establecimientoId));
        }
        foreach ($q->get(['codigo', 'nombres']) as $c) {
            if (strcasecmp($c->codigo, $valor) === 0 || strcasecmp($c->codigo, $especie.'_'.$valor) === 0
                || mb_strtolower((string) Catalogos::nombre($c->nombres, 'es')) === mb_strtolower($valor)) {
                return $c->codigo;
            }
        }

        return null;
    }
}
