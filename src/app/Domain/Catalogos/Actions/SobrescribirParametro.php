<?php

namespace App\Domain\Catalogos\Actions;

use App\Domain\Catalogos\Parametros;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PUT /establecimientos/{e}/parametros/{clave}: el establecimiento sobrescribe un parámetro con una fila
 * nueva `vigente_desde = hoy` (el histórico no se pisa; dos cambios el mismo día dejan el último).
 * Solo claves que existen globalmente, y con el mismo tipo de valor.
 */
final class SobrescribirParametro
{
    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    /** @param array{valor: mixed, especie?: ?string, raza?: ?string, motivo?: ?string} $datos */
    public function handle(Establecimiento $establecimiento, User $usuario, string $clave, array $datos): array
    {
        $especie = $datos['especie'] ?? null;
        $raza = $datos['raza'] ?? null;
        $base = Parametros::resolver($clave, $especie, $raza, null);
        if ($base === null) {
            throw ApiException::noEncontrado(__('El parámetro no existe.'));
        }
        if ($base['valor'] !== null && gettype($base['valor']) !== gettype($datos['valor'])
            && ! (is_numeric($base['valor']) && is_numeric($datos['valor']))) {
            throw ApiException::validacion(__('El valor no tiene el tipo del parámetro.'), ['valor' => [__('Tipo esperado: :t.', ['t' => gettype($base['valor'])])]]);
        }

        return DB::transaction(function () use ($establecimiento, $usuario, $clave, $datos, $especie, $raza, $base) {
            $antes = Parametros::resolver($clave, $especie, $raza, $establecimiento->id);
            DB::table('parametros')->updateOrInsert(
                ['clave' => $clave, 'especie_codigo' => $especie, 'raza_codigo' => $raza, 'establecimiento_id' => $establecimiento->id, 'vigente_desde' => now()->toDateString()],
                ['valor' => json_encode($datos['valor']), 'unidad' => $base['unidad'], 'fuente' => 'productor', 'validado_por_vet' => false, 'created_at' => now()],
            );
            $this->auditoria->handle($establecimiento->id, $usuario->id, 'parametro', null, 'editar',
                ['clave' => $clave, 'especie' => $especie, 'raza' => $raza, 'valor' => $antes['valor'] ?? null],
                ['clave' => $clave, 'especie' => $especie, 'raza' => $raza, 'valor' => $datos['valor']], $datos['motivo'] ?? null);

            return Parametros::resolver($clave, $especie, $raza, $establecimiento->id);
        });
    }
}
