<?php

namespace App\Http\Resources\V1\Reproduccion;

use App\Http\Resources\V1\Hacienda\AnimalResumenResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Schema `PartoCalendario`: la hembra (AnimalResumen) con su FPP, precisión, días que faltan (negativo si pasó) y si está vencido. */
class PartoCalendarioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'animal' => AnimalResumenResource::campos($p['fila']),
            'fecha_probable_parto' => $p['fecha_probable_parto'],
            'fpp_precision' => $p['fpp_precision'],
            'dias_para_parto' => $p['dias_para_parto'],
            'vencido' => $p['vencido'],
        ];
    }
}
