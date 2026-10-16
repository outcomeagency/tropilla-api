<?php

namespace App\Http\Resources\V1\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Schema `Me`: usuario + membresías con abilities calculadas (la UI no reimplementa la matriz).
 *
 * @property User $resource
 */
class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'usuario' => UsuarioResource::make($this->resource)->toArray($request),
            'membresias' => MembresiaResumenResource::deUsuario($this->resource, $request),
            'comprador' => null, // TPA-57
            'profesional' => null, // TPA-58
            'organizaciones' => DB::table('organizacion_miembros as m')->join('organizaciones as o', 'o.id', '=', 'm.organizacion_id')
                ->where('m.usuario_id', $this->resource->id)->where('m.estado', 'activa')->whereNull('o.archivado_en')
                ->orderBy('o.nombre')->get(['o.id', 'o.nombre', 'm.rol'])->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'rol' => $m->rol])->all(),
        ];
    }
}
