<?php

declare(strict_types=1);

namespace App\Domain\Integridad\Anclaje;

use RuntimeException;

/**
 * Lectura/escritura mínima del formato OpenTimestamps (`.ots`, python-opentimestamps): cabecera + op de hash del archivo +
 * digest + árbol de timestamps. Un nodo tiene atestaciones y ramas `[op, arg, hijo]`; varias ramas se separan con 0xff.
 * Ops soportadas: append (0xf0), prepend (0xf1), sha256 (0x08), sha1 (0x02), ripemd160 (0x03), keccak256 (0x67, sin cálculo).
 * Atestaciones: pendiente (calendario, URI) y Bitcoin (altura de bloque).
 *
 * @phpstan-type Nodo array{msg: string, atestaciones: list<array{tag: string, payload: string}>, ops: list<array{op: int, arg: string, hijo: array<string, mixed>}>}
 */
final class Ots
{
    public const CABECERA = "\x00OpenTimestamps\x00\x00Proof\x00\xbf\x89\xe2\xe8\x84\xe8\x92\x94";

    public const TAG_PENDIENTE = "\x83\xdf\xe3\x0d\x2e\xf9\x0c\x8e";

    public const TAG_BITCOIN = "\x05\x88\x96\x0d\x73\xd7\x19\x01";

    private const OP_BINARIAS = [0xF0, 0xF1];

    private const OP_UNARIAS = [0x08, 0x02, 0x03, 0x67, 0xF2, 0xF3];

    private string $buf = '';

    private int $pos = 0;

    /** Archivo `.ots` completo para un digest SHA-256 y su árbol. @param array<string, mixed> $nodo */
    public static function archivo(string $digest, array $nodo): string
    {
        return self::CABECERA."\x01"."\x08".$digest.self::serializar($nodo);
    }

    /** @return array{digest: string, nodo: array<string, mixed>} */
    public static function leerArchivo(string $bytes): array
    {
        if (! str_starts_with($bytes, self::CABECERA)) {
            throw new RuntimeException('No es un archivo OpenTimestamps.');
        }
        $r = new self;
        $r->buf = $bytes;
        $r->pos = strlen(self::CABECERA);
        if ($r->leerUint() !== 1 || $r->byte() !== 0x08) {
            throw new RuntimeException('Versión u op de hash no soportada (se espera v1 + sha256).');
        }
        $digest = $r->leer(32);

        return ['digest' => $digest, 'nodo' => $r->timestamp($digest)];
    }

    /** Timestamp serializado (respuesta de un calendario) a partir de `$msg`. @return array<string, mixed> */
    public static function leerTimestamp(string $bytes, string $msg): array
    {
        $r = new self;
        $r->buf = $bytes;

        return $r->timestamp($msg);
    }

    /** @param array<string, mixed> $nodo */
    public static function serializar(array $nodo): string
    {
        $items = [];
        foreach ($nodo['atestaciones'] as $a) {
            $items[] = "\x00".$a['tag'].self::varbytes($a['payload']);
        }
        foreach ($nodo['ops'] as $o) {
            $items[] = chr($o['op']).(in_array($o['op'], self::OP_BINARIAS, true) ? self::varbytes($o['arg']) : '').self::serializar($o['hijo']);
        }
        if ($items === []) {
            throw new RuntimeException('Timestamp vacío.');
        }
        $out = '';
        $ultimo = count($items) - 1;
        foreach ($items as $i => $item) {
            $out .= ($i < $ultimo ? "\xff" : '').$item;
        }

        return $out;
    }

    /** Aplica una op al mensaje. */
    public static function aplicar(int $op, string $arg, string $msg): string
    {
        return match ($op) {
            0xF0 => $msg.$arg,
            0xF1 => $arg.$msg,
            0x08 => hash('sha256', $msg, true),
            0x02 => hash('sha1', $msg, true),
            0x03 => hash('ripemd160', $msg, true),
            0xF2 => strrev($msg),
            0xF3 => bin2hex($msg),
            default => throw new RuntimeException(sprintf('Op 0x%02x no soportada.', $op)),
        };
    }

    /**
     * Atestaciones pendientes: [uri, mensaje (commitment), ruta de índices de op hasta el nodo].
     *
     * @param  array<string, mixed>  $nodo
     * @param  list<int>  $ruta
     * @return list<array{uri: string, msg: string, ruta: list<int>}>
     */
    public static function pendientes(array $nodo, array $ruta = []): array
    {
        $out = [];
        foreach ($nodo['atestaciones'] as $a) {
            if ($a['tag'] === self::TAG_PENDIENTE) {
                $out[] = ['uri' => self::leerVarbytes($a['payload']), 'msg' => $nodo['msg'], 'ruta' => $ruta];
            }
        }
        foreach ($nodo['ops'] as $i => $o) {
            $out = [...$out, ...self::pendientes($o['hijo'], [...$ruta, $i])];
        }

        return $out;
    }

    /** Menor altura de bloque Bitcoin atestada en el árbol (null si todavía no hay). @param array<string, mixed> $nodo */
    public static function alturaBitcoin(array $nodo): ?int
    {
        $min = null;
        foreach ($nodo['atestaciones'] as $a) {
            if ($a['tag'] === self::TAG_BITCOIN) {
                $h = self::leerVaruintDe($a['payload']);
                $min = $min === null ? $h : min($min, $h);
            }
        }
        foreach ($nodo['ops'] as $o) {
            $h = self::alturaBitcoin($o['hijo']);
            if ($h !== null) {
                $min = $min === null ? $h : min($min, $h);
            }
        }

        return $min;
    }

    /**
     * Injerta en el nodo de `$ruta` las ramas/atestaciones del timestamp actualizado (mismo mensaje).
     *
     * @param  array<string, mixed>  $nodo
     * @param  list<int>  $ruta
     * @param  array<string, mixed>  $nuevo
     * @return array<string, mixed>
     */
    public static function injertar(array $nodo, array $ruta, array $nuevo): array
    {
        if ($ruta === []) {
            foreach ($nuevo['atestaciones'] as $a) {
                if (! in_array($a, $nodo['atestaciones'], true)) {
                    $nodo['atestaciones'][] = $a;
                }
            }
            foreach ($nuevo['ops'] as $o) {
                $nodo['ops'][] = $o;
            }

            return $nodo;
        }
        $i = array_shift($ruta);
        $nodo['ops'][$i]['hijo'] = self::injertar($nodo['ops'][$i]['hijo'], $ruta, $nuevo);

        return $nodo;
    }

    public static function varuint(int $n): string
    {
        $out = '';
        do {
            $b = $n & 0x7F;
            $n >>= 7;
            $out .= chr($n > 0 ? $b | 0x80 : $b);
        } while ($n > 0);

        return $out;
    }

    public static function varbytes(string $b): string
    {
        return self::varuint(strlen($b)).$b;
    }

    // ---- lectura ----

    /** @return array<string, mixed> */
    private function timestamp(string $msg): array
    {
        $nodo = ['msg' => $msg, 'atestaciones' => [], 'ops' => []];
        while (true) {
            $tag = $this->byte();
            $fork = $tag === 0xFF;
            if ($fork) {
                $tag = $this->byte();
            }
            $this->item($nodo, $tag, $msg);
            if (! $fork) {
                return $nodo;
            }
        }
    }

    /** @param array<string, mixed> $nodo */
    private function item(array &$nodo, int $tag, string $msg): void
    {
        if ($tag === 0x00) {
            $t = $this->leer(8);
            $nodo['atestaciones'][] = ['tag' => $t, 'payload' => $this->leer($this->leerUint())];

            return;
        }
        if (in_array($tag, self::OP_BINARIAS, true)) {
            $arg = $this->leer($this->leerUint());
        } elseif (in_array($tag, self::OP_UNARIAS, true)) {
            $arg = '';
        } else {
            throw new RuntimeException(sprintf('Tag 0x%02x desconocido.', $tag));
        }
        $nuevo = $tag === 0x67 ? $msg : self::aplicar($tag, $arg, $msg);
        $nodo['ops'][] = ['op' => $tag, 'arg' => $arg, 'hijo' => $this->timestamp($nuevo)];
    }

    private function byte(): int
    {
        return ord($this->leer(1));
    }

    private function leer(int $n): string
    {
        if ($this->pos + $n > strlen($this->buf)) {
            throw new RuntimeException('OTS truncado.');
        }
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;

        return $s;
    }

    private function leerUint(): int
    {
        $n = 0;
        $shift = 0;
        do {
            $b = $this->byte();
            $n |= ($b & 0x7F) << $shift;
            $shift += 7;
        } while ($b & 0x80);

        return $n;
    }

    private static function leerVarbytes(string $s): string
    {
        $r = new self;
        $r->buf = $s;

        return $r->leer($r->leerUint());
    }

    private static function leerVaruintDe(string $s): int
    {
        $r = new self;
        $r->buf = $s;

        return $r->leerUint();
    }
}
