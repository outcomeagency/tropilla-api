<?php

namespace App\Http\Resources\V1\Comercial;

use App\Domain\Comercial\Actions\GestionarOferta;
use App\Domain\Comercial\Models\Oferta;
use App\Domain\Documentos\Fotos;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Schema `Oferta` (vista interna del productor). `precarga` = derivados actuales de la hacienda.
 *
 * @property Oferta $resource
 */
class OfertaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        ['precarga' => $pre, 'avisos' => $avisos] = app(GestionarOferta::class)->evaluar($o);
        unset($pre['_reactores'], $pre['_animal_ids']);

        return [
            'id' => $o->id,
            'codigo_publico' => $o->codigo_publico,
            'url_publica' => config('tropilla.web_url').'/o/'.$o->codigo_publico,
            'tipo' => $o->tipo,
            'especie' => $o->especie_codigo,
            'categorias' => $o->categorias,
            'razas' => $o->razas,
            'lote_id' => $o->lote_id,
            'animal_ids' => $o->animalIds(),
            'cantidad' => $o->cantidad,
            'estado' => $o->estado,
            'mostrar' => (object) ($o->mostrar ?? []),
            'condiciones' => (object) ($o->condiciones ?? []),
            'textos' => (object) ($o->textos ?? []),
            'fotos' => Fotos::lista($o->fotos ?? [], false), // bugfix TPA-54: Foto[] (URL firmada)
            'precarga' => $pre,
            'avisos' => $avisos,
            'publicacion_vigente' => $o->publicacion_vigente,
            'publicada_en' => Formato::fechaHora($o->publicada_en),
            'pdf' => null,
            'consultas_count' => DB::getSchemaBuilder()->hasTable('consultas') ? DB::table('consultas')->where('oferta_id', $o->id)->count() : 0,
            'row_version' => $o->row_version,
        ];
    }
}
