<?php

namespace App\Domain\Catalogos\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Raza, causa de muerte, signo o principio activo propio del establecimiento.
 * Código estable `X_<SLUG>` (razas) / `x_<slug>` (listas); si ya existe se devuelve el mismo (idempotente).
 */
final class AgregarCatalogoPropio
{
    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /** @param array{lista: string, nombre: string, especie?: ?string} $datos */
    public function handle(Establecimiento $establecimiento, User $usuario, array $datos): string
    {
        return DB::transaction(function () use ($establecimiento, $usuario, $datos) {
            $slug = Str::slug($datos['nombre'], '_');
            $nombres = json_encode(['es' => $datos['nombre'], 'en' => $datos['nombre'], 'pt' => $datos['nombre']], JSON_UNESCAPED_UNICODE);

            if ($datos['lista'] === 'razas') {
                $codigo = 'X_'.$datos['especie'].'_'.strtoupper($slug);
                $existe = DB::table('razas')->where('establecimiento_id', $establecimiento->id)->where('codigo', $codigo)->exists();
                if (! $existe) {
                    DB::table('razas')->insert(['codigo' => $codigo, 'especie_codigo' => $datos['especie'], 'nombres' => $nombres,
                        'establecimiento_id' => $establecimiento->id, 'activa' => true, 'orden' => 900]);
                }
            } else {
                $codigo = 'x_'.$slug;
                $existe = DB::table('catalogo_items')->where('lista', $datos['lista'])->where('establecimiento_id', $establecimiento->id)->where('codigo', $codigo)->exists();
                if (! $existe) {
                    DB::table('catalogo_items')->insert(['lista' => $datos['lista'], 'codigo' => $codigo, 'nombres' => $nombres,
                        'especies' => isset($datos['especie']) ? '{'.$datos['especie'].'}' : null,
                        'establecimiento_id' => $establecimiento->id, 'orden' => 900, 'activo' => true]);
                }
            }
            if (! $existe) {
                $this->auditoria->handle($establecimiento->id, $usuario->id, 'catalogo_propio', null, 'crear', null, ['lista' => $datos['lista'], 'codigo' => $codigo, 'nombre' => $datos['nombre']]);
            }

            return $codigo;
        });
    }
}
