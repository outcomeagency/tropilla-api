<?php

namespace App\Domain\Documentos\Borradores\Familias;

use App\Domain\Documentos\Borradores\Campos;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Libro de Registro de Tratamientos (TPA-65, Res. SENASA 666/2011; senasa-planillas.md §7 y §12). Esquema
 * `libro_tratamientos.666_2011/1` con el contenido mínimo del anexo: fecha de inicio, animales/lote, diagnóstico,
 * producto y principio activo, dosis/vía, duración, responsable, retiro para carne/leche y observaciones.
 * Cada fila confirmada es un `sanidad.tratamiento` de TROPILLA: **un registro propio, separado del libro foliado y
 * rubricado**, que sigue siendo el documento oficial (la foto queda como fuente). Una fila se omite marcando su fecha
 * «no aplica». Lo manuscrito dudoso queda como sugerencia hasta que la persona lo confirma de a uno (RevisarCampos).
 */
final class LibroTratamientos extends FamiliaBase
{
    public const ESQUEMA = 'libro_tratamientos.666_2011/1';

    public const VIAS = ['subcutanea', 'intramuscular', 'endovenosa', 'oral', 'topica', 'intramamaria', 'otra'];

    protected const ETIQUETAS = [
        'subcutanea' => 'Subcutánea', 'intramuscular' => 'Intramuscular', 'endovenosa' => 'Endovenosa', 'oral' => 'Oral',
        'topica' => 'Tópica / pour-on', 'intramamaria' => 'Intramamaria', 'otra' => 'Otra',
    ];

    protected const SINONIMOS = [
        'subcutanea' => ['subcut', 's.c.', 's/c'], 'intramuscular' => ['intramusc', 'i.m.', 'i/m'], 'endovenosa' => ['endoven', 'intraven', 'e.v.', 'i.v.'],
        'oral' => ['oral', 'bucal'], 'topica' => ['topic', 'pour', 'dorsal'], 'intramamaria' => ['intramam', 'pomo'],
    ];

    public function codigo(): string
    {
        return 'libro_tratamientos';
    }

    public function tipoDocumento(): string
    {
        return 'libro_tratamientos';
    }

    protected function variantes(): array
    {
        return ['666_2011' => self::ESQUEMA];
    }

    protected function escalares(string $variante): array
    {
        return [
            'establecimiento' => ['libro', 'Establecimiento', 'texto', null, false],
            'renspa' => ['libro', 'RENSPA', 'texto', null, false],
            'titular' => ['libro', 'Titular', 'texto', null, false],
            'folio' => ['libro', 'Folio', 'texto', null, false],
            'rubrica' => ['libro', 'Rubricado por (oficina / sello)', 'texto', null, false],
            'veterinario_responsable' => ['libro', 'Veterinario responsable', 'texto', null, false],
            'matricula_veterinario' => ['libro', 'Matrícula', 'texto', null, false],
        ];
    }

    protected function filasDef(): array
    {
        return ['tratamientos' => [
            'fecha_inicio' => ['Fecha de inicio', 'fecha', null, true],
            'animales' => ['Animales / lote (como figura)', 'texto', null, false],
            'caravanas' => ['Caravanas', 'texto', null, false],
            'lote_id' => ['Lote en TROPILLA', 'lote', [], false],
            'diagnostico' => ['Diagnóstico / indicación', 'texto', null, true],
            'producto' => ['Producto (nombre comercial)', 'texto', null, true],
            'principio_activo' => ['Principio activo', 'texto', null, false],
            'dosis' => ['Dosis', 'texto', null, false],
            'via' => ['Vía', 'opcion', self::VIAS, false],
            'duracion_dias' => ['Duración (días)', 'entero', null, false],
            'responsable' => ['Responsable / veterinario', 'texto', null, false],
            'retiro_carne_dias' => ['Retiro carne (días)', 'entero', null, true],
            'retiro_leche_dias' => ['Retiro leche (días)', 'entero', null, false],
            'observaciones' => ['Observaciones', 'texto', null, false],
        ]];
    }

    public function instrucciones(): string
    {
        return <<<'TXT'
        Es una hoja del Libro de Registro de Tratamientos de un establecimiento ganadero de Argentina (Res. SENASA 666/2011),
        casi siempre manuscrita, foliada y rubricada. variante: "666_2011" si es una hoja de libro de tratamientos;
        "desconocida" si no lo es.
        Transcribí cada casillero tal como está escrito (fechas y números como figuran, sin reformatear ni completar). Para
        cada campo: valor (null si está vacío, tachado o ilegible), confianza entre 0 y 1 (manuscrito dudoso ≤ 0.6; enmiendas
        o sobrescrituras ≤ 0.5), pagina (1..n) y alternativas si un trazo admite otra lectura (p. ej. "17" y "11").
        Encabezado: establecimiento, renspa, titular, folio (número de folio de la hoja), rubrica (oficina local o sello que
        rubrica), veterinario_responsable y matricula_veterinario.
        tratamientos: una fila por cada renglón con datos: fecha_inicio, animales (identificación de animales o lote tal como
        figura), caravanas (solo los números de caravana/RFID si están escritos, separados por coma), diagnostico (o
        indicación), producto (nombre comercial), principio_activo, dosis, via (subcutánea, intramuscular, endovenosa, oral,
        tópica/pour-on, intramamaria u otra), duracion_dias, responsable (quien aplicó o indicó), retiro_carne_dias,
        retiro_leche_dias (en días) y observaciones. No inventes filas para renglones vacíos.
        problemas: páginas cortadas, borrosas, con reflejo, renglones con enmiendas sin salvar o en blanco intercalados.
        TXT;
    }

    protected function nombreEsquema(string $esquema): string
    {
        return 'Libro de tratamientos (Res. 666/2011)';
    }

    protected function titulosGrupos(): array
    {
        return ['libro' => 'Encabezado del libro', 'tratamientos' => 'Tratamiento :n'];
    }

    protected function etiquetaAgregar(string $grupo): string
    {
        return 'Agregar tratamiento';
    }

    public function bloqueantes(): array
    {
        return ['caravana_desconocida', 'fila_sin_animales'];
    }

    /** Sugiere el lote cuando el renglón nombra uno que existe («Vaquillonas 2025» ↔ lote «Vaquillonas 2025»). */
    public function completar(Establecimiento $est, array $campos): array
    {
        $lotes = DB::table('lotes')->where('establecimiento_id', $est->id)->whereNull('archivado_en')->pluck('id', 'nombre')->all();
        $norm = fn (string $s) => trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($s))));
        for ($i = 0, $n = Campos::filas($campos, 'tratamientos'); $i < $n; $i++) {
            $texto = Campos::valor($campos, "tratamientos.{$i}.animales");
            if ($texto === null || Campos::valor($campos, "tratamientos.{$i}.caravanas") !== null) {
                continue;
            }
            foreach ($lotes as $nombre => $id) {
                if ($norm($nombre) !== '' && str_contains($norm($texto), $norm($nombre))) {
                    self::sugerir($campos, "tratamientos.{$i}.lote_id", $id);
                    break;
                }
            }
        }

        return $campos;
    }

    public function advertencias(Establecimiento $est, array $campos, array $salida): array
    {
        $v = fn (string $r) => Campos::valor($campos, $r);
        $adv = $this->advertenciasLectura($salida, __('una hoja del libro de tratamientos'));
        $propio = self::renspaPropio($est);
        if ($propio && $v('renspa') !== null && ! self::mismoRenspa($propio, $v('renspa'))) {
            $adv[] = ['codigo' => 'renspa_distinto', 'mensaje' => __('El RENSPA del libro no coincide con el del establecimiento.'), 'ruta' => 'renspa'];
        }
        foreach ($this->filasActivas($campos) as $i) {
            $p = "tratamientos.{$i}";
            $fila = __('Tratamiento :n', ['n' => $i + 1]);
            if ($a = $this->advertenciaFecha($v("{$p}.fecha_inicio"), "{$p}.fecha_inicio", __('la fecha de inicio del tratamiento :n', ['n' => $i + 1]))) {
                $adv[] = $a;
            }
            foreach (['diagnostico' => __('el diagnóstico'), 'producto' => __('el producto')] as $col => $nombre) {
                if ($v("{$p}.{$col}") === null) {
                    $adv[] = ['codigo' => 'dato_minimo_faltante', 'ruta' => "{$p}.{$col}", 'mensaje' => __(':f: falta :d (contenido mínimo del libro).', ['f' => $fila, 'd' => $nombre])];
                }
            }
            if ($v("{$p}.producto") !== null && $v("{$p}.retiro_carne_dias") === null) {
                $adv[] = ['codigo' => 'retiro_faltante', 'ruta' => "{$p}.retiro_carne_dias",
                    'mensaje' => __(':f: no figura el retiro para carne. Sin ese dato TROPILLA no puede avisarte cuándo se puede vender.', ['f' => $fila])];
            }
            if ($a = $this->advertenciaCaravanas($est, $v("{$p}.caravanas"), "{$p}.caravanas", $fila)) {
                $adv[] = $a;
            } elseif ($v("{$p}.caravanas") === null && self::lote($est, $v("{$p}.lote_id")) === null) {
                $adv[] = ['codigo' => 'fila_sin_animales', 'ruta' => "{$p}.lote_id",
                    'mensaje' => __(':f: elegí el lote o anotá las caravanas (o marcá la fecha «No aplica» para no registrar la fila).', ['f' => $fila])];
            }
        }

        return $adv;
    }

    public function confirmacion(Establecimiento $est, array $campos, string $documentoId): array
    {
        $v = fn (string $r) => Campos::valor($campos, $r);
        $t = fn (?string $x) => $x === null ? null : mb_substr($x, 0, 200);
        $eventos = [];
        foreach ($this->filasActivas($campos) as $i) {
            $p = "tratamientos.{$i}";
            $fila = __('Tratamiento :n', ['n' => $i + 1]);
            $fecha = $v("{$p}.fecha_inicio") ?? throw ApiException::reglaBloqueante(__(':f: falta la fecha de inicio.', ['f' => $fila]));
            $diag = $v("{$p}.diagnostico") ?? throw ApiException::reglaBloqueante(__(':f: falta el diagnóstico.', ['f' => $fila]));
            $producto = $v("{$p}.producto");
            $via = $v("{$p}.via");
            $dosis = implode(' · ', array_filter([$v("{$p}.dosis"), $via ? self::ETIQUETAS[$via] : null]));
            $obs = implode(' ', array_filter([
                __('Transcripción asistida del Libro de Registro de Tratamientos (Res. 666/2011):f, revisada campo por campo. No reemplaza al libro rubricado.',
                    ['f' => $v('folio') ? ' folio '.$v('folio') : '']),
                $v("{$p}.animales") ? __('Animales según el libro: :a.', ['a' => $v("{$p}.animales")]) : null,
                $v("{$p}.observaciones"),
            ]));
            $eventos[] = [
                'tipo' => 'sanidad.tratamiento', 'ocurrido_en' => $fecha, 'observaciones' => mb_substr($obs, 0, 2000),
                'sujetos' => [$this->sujetoSanitario($est, $v("{$p}.caravanas"), $v("{$p}.lote_id"), $fila)],
                'payload' => array_filter([
                    'diagnostico_presuntivo' => $t($diag),
                    'producto_nombre' => $t($producto === null ? null : ($v("{$p}.principio_activo") ? "{$producto} ({$v("{$p}.principio_activo")})" : $producto)),
                    'dosis_aplicada' => $t($dosis !== '' ? $dosis : null),
                    'duracion_dias' => ($d = $v("{$p}.duracion_dias")) > 0 ? $d : null,
                    'veterinario' => $t($v("{$p}.responsable") ?? $v('veterinario_responsable')),
                    'retiro_carne_dias' => $v("{$p}.retiro_carne_dias"),
                    'retiro_leche_dias' => $v("{$p}.retiro_leche_dias"),
                ], fn ($x) => $x !== null),
            ];
        }
        if ($eventos === []) {
            throw ApiException::reglaBloqueante(__('No hay tratamientos para registrar.'));
        }

        return ['eventos' => $eventos, 'vincular' => []];
    }

    public function metadatosDocumento(array $campos): array
    {
        $vet = Campos::valor($campos, 'veterinario_responsable');

        return $vet === null ? [] : ['emisor' => array_filter(['nombre' => mb_substr($vet, 0, 200), 'identificacion' => Campos::valor($campos, 'matricula_veterinario')])];
    }

    /** @return list<int> filas a registrar (la fecha «no aplica» omite la fila). */
    private function filasActivas(array $campos): array
    {
        $out = [];
        for ($i = 0, $n = Campos::filas($campos, 'tratamientos'); $i < $n; $i++) {
            if (Campos::estado($campos, "tratamientos.{$i}.fecha_inicio") !== 'no_aplica') {
                $out[] = $i;
            }
        }

        return $out;
    }
}
