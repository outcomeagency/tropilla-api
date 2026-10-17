<?php

namespace App\Domain\Comercial\Faena;

use App\Domain\Hacienda\Importacion\LectorPlanilla;

/**
 * Lee el resultado de faena digital del frigorífico (CSV `,`/`;` o XLSX, una fila por res) → reses normalizadas + errores
 * por fila (TPA-67). No guarda nada: la persona revisa la tabla antes de registrar. Encabezados tolerantes a cómo los
 * nombra cada frigorífico (garrón/correlativo, RFID/caravana, media A/B, kg, tipificación…).
 */
final class PlanillaFaena
{
    /** columna normalizada => sinónimos (slug con `_`) */
    private const COLUMNAS = [
        'garron' => ['garron', 'n_garron', 'nro_garron', 'numero_garron', 'garron_n', 'correlativo', 'n_correlativo', 'nro'],
        'identificacion' => ['rfid', 'caravana', 'identificacion', 'id_electronica', 'caravana_electronica', 'eid', 'chip', 'caravana_rfid', 'id_animal'],
        'media_a_kg' => ['media_a', 'media_a_kg', 'peso_media_a', 'kg_media_a', 'media_1', 'peso_media_1', 'media_res_a', 'mr_a'],
        'media_b_kg' => ['media_b', 'media_b_kg', 'peso_media_b', 'kg_media_b', 'media_2', 'peso_media_2', 'media_res_b', 'mr_b'],
        'peso_kg' => ['peso', 'peso_kg', 'kg', 'peso_res', 'kg_res', 'peso_total', 'kg_total', 'peso_carcasa', 'peso_canal', 'pcc', 'peso_gancho'],
        'categoria' => ['categoria', 'cat', 'categoria_faena'],
        'tipificacion' => ['tipificacion', 'tipif', 'clasificacion', 'grado', 'conformacion', 'tipo'],
        'decomiso' => ['decomiso', 'decomisos', 'dec'],
        'observaciones' => ['observaciones', 'obs', 'observacion'],
    ];

    /**
     * @return array{reses: list<array<string, mixed>>, errores: list<array{fila: int, mensaje: string}>, columnas: list<string>}
     */
    public static function leer(string $contenido, string $formato): array
    {
        $filas = LectorPlanilla::leer($contenido, $formato);
        $reses = [];
        $errores = [];
        $usadas = [];
        foreach ($filas as $i => $f) {
            $r = [];
            foreach (self::COLUMNAS as $col => $sinonimos) {
                foreach ($sinonimos as $s) {
                    if (array_key_exists($s, $f) && $f[$s] !== '') {
                        $r[$col] = $f[$s];
                        $usadas[$col] = true;
                        break;
                    }
                }
            }
            foreach (['media_a_kg', 'media_b_kg', 'peso_kg'] as $k) {
                if (isset($r[$k])) {
                    $kg = self::kg($r[$k]);
                    if ($kg === null) {
                        $errores[] = ['fila' => $i + 2, 'mensaje' => __('«:v» no es un peso válido.', ['v' => $r[$k]])];
                    }
                    $r[$k] = $kg;
                }
            }
            if (! isset($r['peso_kg']) && isset($r['media_a_kg'], $r['media_b_kg'])) {
                $r['peso_kg'] = Faena::sumar($r['media_a_kg'], $r['media_b_kg']);
            }
            if (! isset($r['garron'])) {
                $errores[] = ['fila' => $i + 2, 'mensaje' => __('Falta el número de garrón.')];
            }
            if (! isset($r['peso_kg'])) {
                $errores[] = ['fila' => $i + 2, 'mensaje' => __('Falta el peso de la res (o de las dos medias).')];
            }
            $reses[] = array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 100) : $v, $r);
        }

        return ['reses' => $reses, 'errores' => $errores, 'columnas' => array_keys($usadas)];
    }

    /** «123,5» / «1.234,5» / «123.5 kg» → «123.5» (null si no es un peso). */
    public static function kg(string $v): ?string
    {
        $s = trim(preg_replace('/\s*kg\s*$/i', '', $v));
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
        if (! preg_match('/^\d{1,5}(\.\d+)?$/', $s) || (float) $s <= 0) {
            return null;
        }

        return Faena::kg($s);
    }
}
