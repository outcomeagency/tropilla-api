<?php

namespace App\Http\Resources\V1\Publico;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Allowlist final para los bloques ampliados del perfil público. */
final class PerfilBloqueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dato = (array) $this->resource;

        return match ($request->route('bloque')) {
            'reproductores' => array_map([self::class, 'reproductor'], array_filter($dato, 'is_array')),
            'composicion' => ['por_especie' => $dato['por_especie'] ?? [], 'razas' => $dato['razas'] ?? []],
            'indicadores' => array_intersect_key($dato, array_flip(['prenez_ultimo_tacto', 'partos_por_temporada', 'anios_en_tropilla', 'antiguedad_fuente', 'plan_sanitario'])),
            'galeria' => ['fotos' => array_map(fn ($f) => array_intersect_key((array) $f, array_flip(['sha256', 'url', 'alt', 'ancho', 'alto'])), $dato['fotos'] ?? [])],
            default => [],
        };
    }

    private static function reproductor(array $r): array
    {
        $out = array_intersect_key($r, array_flip(['tipo', 'nombre', 'rp', 'registro', 'raza', 'especie', 'sexo', 'centro', 'fecha_nacimiento', 'peso_kg', 'peso_fecha', 'condicion_corporal', 'partos', 'crias', 'foto']));
        foreach (['madre', 'padre'] as $p) {
            $out[$p] = isset($r[$p]) && is_array($r[$p]) ? self::reproductor($r[$p]) : null;
        }

        return $out;
    }
}
