<?php

namespace App\Domain\Hacienda\Eventos;

use App\Casts\PgTextArray;
use Illuminate\Support\Facades\DB;

/**
 * Lectura de `tipos_evento` (datos, no código): sujetos admitidos, si crea animales, sexo requerido y
 * versión vigente del JSON Schema. Memoizado por proceso.
 */
final class TiposEvento
{
    /** @var array<string, object|null> */
    private static array $cache = [];

    /** @return object{codigo: string, modulo: string, nombres: string, fase: string, activo: bool, crea_animales: bool, requiere_sexo: ?string, sujetos: list<string>, version_vigente: int}|null */
    public static function get(string $codigo): ?object
    {
        if (! array_key_exists($codigo, self::$cache)) {
            $t = DB::table('tipos_evento')->where('codigo', $codigo)->first();
            if ($t !== null) {
                $t->sujetos = PgTextArray::parse($t->sujetos_admitidos);
                $t->crea_animales = (bool) $t->crea_animales;
                $t->version_vigente = (int) DB::table('tipos_evento_versiones')->where('tipo_codigo', $codigo)->max('version');
            }
            self::$cache[$codigo] = $t;
        }

        return self::$cache[$codigo];
    }

    public static function olvidar(): void
    {
        self::$cache = [];
    }

    /** Ability de la matriz para crear/corregir eventos de un módulo (modelo-datos §13.1). */
    public static function ability(string $modulo, bool $correccion): string
    {
        return match ($modulo) {
            'establecimiento' => 'establecimiento_eventos.crear',
            default => $modulo.($correccion ? '.corregir' : '.crear'),
        };
    }
}
