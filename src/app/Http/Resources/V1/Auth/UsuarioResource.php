<?php

namespace App\Http\Resources\V1\Auth;

use App\Domain\Documentos\Fotos;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Schema `Usuario` del contrato.
 *
 * @property User $resource
 */
class UsuarioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $u = $this->resource;

        return [
            'id' => $u->id,
            'nombre' => $u->nombre,
            'email' => $u->email,
            'telefono' => $u->telefono,
            'email_verificado' => $u->emailVerificado(),
            'idioma' => $u->idioma,
            'pais' => $u->pais,
            'foto_url' => ($foto = Fotos::privada($u->foto_sha256))['url'] ?? null, // bugfix TPA-54: URL firmada
            'foto' => $foto,
            'es_staff' => (bool) $u->es_staff,
            'es_comprador' => false, // TPA-57
            'es_profesional' => false, // TPA-58
            'es_proveedor' => DB::table('proveedores')->where('usuario_id', $u->id)->exists(), // TPA-82
            'created_at' => Formato::fechaHora($u->created_at),
        ];
    }
}
