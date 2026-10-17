<?php

namespace App\Domain\Perfil\Services;

use App\Domain\Establecimientos\Models\Establecimiento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Fila de `perfiles_publicos` del establecimiento (se crea despublicada, con slug derivado del nombre). */
final class Perfiles
{
    public static function asegurar(Establecimiento $est): object
    {
        $p = DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->first();
        if ($p !== null) {
            return $p;
        }
        $base = substr(Str::slug($est->nombre) ?: 'establecimiento', 0, 50);
        $base = strlen($base) < 3 ? $base.'-campo' : $base;
        $slug = $base;
        for ($i = 2; DB::table('perfiles_publicos')->where('slug', $slug)->exists() || DB::table('perfil_slugs_historicos')->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }
        DB::table('perfiles_publicos')->insert([
            'establecimiento_id' => $est->id, 'slug' => $slug, 'secciones' => json_encode(self::seccionesDefault()),
            'textos' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('perfiles_publicos')->where('establecimiento_id', $est->id)->first();
    }

    /** @return array<string, array<string, mixed>> */
    public static function seccionesDefault(): array
    {
        $s = fn (bool $activa = true) => ['activa' => $activa, 'congelada_en' => null, 'snapshot' => null];

        return [
            'portada' => $s(), 'actividad' => $s(), 'rodeo' => [...$s(), 'modo' => 'rango'], 'sanidad' => $s(),
            'trazabilidad' => $s(), 'documentos' => $s(false), 'oferta' => $s(), 'contacto' => $s(),
            'reproductores' => [...$s(false), 'ids' => []], 'composicion' => [...$s(false), 'modo' => 'rango'],
            'indicadores' => $s(false), 'galeria' => $s(false),
        ];
    }
}
