<?php

namespace App\Domain\Hacienda\Importacion;

use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/** Lee CSV (`,` o `;`, UTF-8 con o sin BOM) o XLSX (primera hoja) → filas asociativas por encabezado normalizado. */
final class LectorPlanilla
{
    /** @return list<array<string, string>> */
    public static function leer(string $contenido, string $formato): array
    {
        $crudas = $formato === 'xlsx' ? self::xlsx($contenido) : self::csv($contenido);
        if ($crudas === []) {
            return [];
        }
        $encabezados = array_map(fn ($h) => Str::slug(trim((string) $h), '_'), array_shift($crudas));
        $filas = [];
        foreach ($crudas as $c) {
            if (count(array_filter($c, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $fila = [];
            foreach ($encabezados as $i => $h) {
                if ($h !== '') {
                    $fila[$h] = trim((string) ($c[$i] ?? ''));
                }
            }
            $filas[] = $fila;
        }

        return $filas;
    }

    /** @return list<list<string>> */
    private static function csv(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        $primera = strtok($contenido, "\n") ?: '';
        $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
        $h = fopen('php://temp', 'r+');
        fwrite($h, $contenido);
        rewind($h);
        $filas = [];
        while (($f = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            $filas[] = array_map('strval', $f);
        }
        fclose($h);

        return $filas;
    }

    /** @return list<list<string>> */
    private static function xlsx(string $contenido): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        file_put_contents($tmp, $contenido);
        $reader = new XlsxReader;
        $reader->open($tmp);
        $filas = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $filas[] = array_map(function ($v) {
                    return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v;
                }, $row->toArray());
            }
            break;
        }
        $reader->close();
        @unlink($tmp);

        return $filas;
    }
}
