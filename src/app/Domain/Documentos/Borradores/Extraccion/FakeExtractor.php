<?php

namespace App\Domain\Documentos\Borradores\Extraccion;

/**
 * Driver de tests: devuelve la salida programada (o falla) y registra lo que recibió. Nunca se usa fuera de testing.
 */
final class FakeExtractor implements Extractor
{
    /** @var array<string, mixed>|null */
    public static ?array $salida = null;

    public static ?ExtraccionFallida $falla = null;

    /** @var list<array{paginas: int, mimes: list<string>, instrucciones: string}> */
    public static array $llamadas = [];

    public static function reset(): void
    {
        self::$salida = null;
        self::$falla = null;
        self::$llamadas = [];
    }

    public function extraer(array $paginas, string $instrucciones, array $esquema, ?string $instruccionSistema = null): ResultadoExtraccion
    {
        self::$llamadas[] = ['paginas' => count($paginas), 'mimes' => array_column($paginas, 'mime'), 'instrucciones' => $instrucciones];
        if (self::$falla !== null) {
            throw self::$falla;
        }

        return new ResultadoExtraccion(self::$salida ?? [], 'fake', 'fake-1', 100 * count($paginas), 50);
    }
}
