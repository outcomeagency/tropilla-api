<?php

namespace App\Http\Resources\V1\Perfil;

use App\Casts\PgTextArray;
use App\Domain\Perfil\Services\PerfilPublicoBuilder;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** Schema `Perfil` (configuración del perfil, vista del productor). */
class PerfilResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        [$completitud, $faltantes] = PerfilPublicoBuilder::completitud($p);
        $fecha = fn ($v) => $v ? Formato::fechaHora(Carbon::parse($v)) : null;

        return [
            'establecimiento_id' => $p->establecimiento_id,
            'slug' => $p->slug,
            'url_publica' => config('tropilla.web_url').'/p/'.$p->slug,
            'publicado' => (bool) $p->publicado,
            'en_directorio' => (bool) $p->en_directorio,
            'indexable' => (bool) $p->indexable,
            'preview_aceptada_en' => $fecha($p->preview_aceptada_en),
            'primera_publicacion_en' => $fecha($p->primera_publicacion_en),
            'secciones' => (object) (json_decode($p->secciones, true) ?: []),
            'textos' => (object) (json_decode($p->textos, true) ?: []),
            'idiomas' => PgTextArray::parse($p->idiomas),
            'contacto_visible' => (object) (json_decode($p->contacto_visible, true) ?: []),
            'ubicacion_publica' => $p->ubicacion_publica,
            'fotos' => [],
            'asociaciones_visibles' => (bool) $p->asociaciones_visibles,
            'completitud' => $completitud,
            'faltantes' => $faltantes,
            'row_version' => (int) $p->row_version,
        ];
    }
}
