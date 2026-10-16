<?php

namespace App\Http\Resources\V1\Hacienda;

use App\Domain\Catalogos\Catalogos;
use App\Domain\Comercial\Services\Compromisos;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Schema `AnimalResumen` desde una fila de ConsultasAnimales. Edad y retiro vigente se calculan al leer
 * (dependen de «hoy»). `reactor` (privado) solo llega a miembros del establecimiento.
 */
class AnimalResumenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public static function campos(object $a): array
    {
        $hoy = now()->toDateString();

        return [
            'id' => $a->animal_id,
            'especie' => $a->especie_codigo,
            'sexo' => $a->sexo,
            'raza' => $a->raza_codigo,
            'categoria' => $a->categoria_codigo,
            'categoria_nombre' => (string) Catalogos::nombre($a->categoria_nombres, app()->getLocale()),
            'categoria_sugerida' => $a->categoria_sugerida_codigo,
            'identificacion_principal' => $a->identificacion_principal,
            'edad_meses' => $a->fecha_nacimiento ? (int) floor(Carbon::parse($a->fecha_nacimiento)->diffInMonths(now(), true)) : null,
            'estado' => $a->estado,
            'estado_reproductivo' => $a->estado_reproductivo,
            'fecha_probable_parto' => $a->fecha_probable_parto,
            'peso_kg' => Formato::decimal($a->peso_kg),
            'peso_fecha' => $a->peso_fecha,
            'lote' => $a->lote_id ? ['id' => $a->lote_id, 'nombre' => $a->lote_nombre] : null,
            'potrero' => $a->potrero_actual_id ? ['id' => $a->potrero_actual_id, 'nombre' => $a->potrero_nombre] : null,
            'retiro_vigente' => ($a->retiro_carne_hasta !== null && $a->retiro_carne_hasta >= $hoy) || ($a->retiro_leche_hasta !== null && $a->retiro_leche_hasta >= $hoy),
            'reactor' => $a->reactor ? ($a->reactor_prueba ?? 'positivo') : null,
            'foto_url' => null,
            // Aditivo (duda nº 1 de Claude Design): libre | en_oferta | en_contrato | vendido.
            'compromiso' => Compromisos::deAnimal($a->animal_id),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::campos($this->resource);
    }
}
