<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Formatos de salida de la API (api-v1.md §5): fecha-hora UTC con milisegundos,
 * fechas YYYY-MM-DD y decimales como string canónico (sin ceros finales).
 */
final class Formato
{
    public static function fechaHora(?DateTimeInterface $t): ?string
    {
        if ($t === null) {
            return null;
        }

        return Carbon::instance($t)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function fecha(?DateTimeInterface $t): ?string
    {
        return $t === null ? null : Carbon::instance($t)->format('Y-m-d');
    }

    /** "412.50" -> "412.5", "100.00" -> "100", "-0.0" -> "0". */
    public static function decimal(string|int|float|null $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = is_float($v) ? rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.') : (string) $v;
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        $neg = str_starts_with($s, '-');
        $abs = ltrim($neg ? substr($s, 1) : $s, '0');
        if ($abs === '' || str_starts_with($abs, '.')) {
            $abs = '0'.$abs;
        }
        if ($abs === '0') {
            return '0';
        }

        return ($neg ? '-' : '').$abs;
    }
}
