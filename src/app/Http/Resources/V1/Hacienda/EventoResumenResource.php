<?php

namespace App\Http\Resources\V1\Hacienda;

use App\Domain\Hacienda\Eventos\ResumenEvento;
use App\Domain\Integridad\Confianza;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema `EventoResumen` (timelines y actividad). Se construye desde una fila de `eventos_vigentes`
 * con `autor_nombre`, `autor_rol`, `animales_count` y opcionalmente `heredado_de_lote`.
 */
class EventoResumenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $e = $this->resource;
        $p = is_string($e->payload) ? json_decode($e->payload, true) : (array) $e->payload;
        $sobre = json_decode($e->sobre, true);

        return [
            'id' => $e->id,
            'evento_raiz_id' => $e->evento_raiz_id,
            'version' => (int) $e->version,
            'tipo' => $e->tipo,
            'tipo_nombre' => ResumenEvento::tipoNombre($e->tipo),
            'ocurrido_en' => $sobre['ocurrido_en'],
            'resumen' => $e->accion === 'anulacion' ? 'Anulado: '.($e->motivo ?? '') : ResumenEvento::de($e->tipo, $p, $e->conteo ? json_decode($e->conteo, true) : null, (int) ($e->animales_count ?? 0)),
            'autor' => ['id' => $e->autor_id, 'nombre' => $e->autor_nombre ?? '', 'rol' => $e->autor_rol ?? null],
            'confianza' => Confianza::deRegistro((int) $e->registro_integridad_id),
            'corregido' => (int) $e->version > 1,
            'anulado' => $e->accion === 'anulacion',
            'heredado_de_lote' => (bool) ($e->heredado_de_lote ?? false),
        ];
    }
}
