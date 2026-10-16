<?php

namespace App\Domain\Hacienda\Actions;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Domain\Integridad\Models\Cadena;
use App\Domain\Sistema\Actions\RegistrarAuditoria;
use App\Domain\Sync\Actions\RegistrarCambio;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «Descartar sugerencia» de recategorización (bugfix TPA-25): el usuario dice «este animal no pasa a esa categoría por
 * ahora». Se guarda (animal, categoría) en `sugerencias_descartadas` y el sugeridor no la vuelve a proponer; si las reglas
 * llegan a sugerir otra categoría, esa sí aparece. Todo o nada; idempotente (descartar dos veces no falla).
 * No es un evento (no cambia nada del animal): auditoría + cambio del animal para el pull.
 */
final class DescartarSugerencias
{
    public function __construct(private readonly RegistrarCambio $cambio, private readonly RegistrarAuditoria $auditoria) {}

    /**
     * @param  list<array{animal_id: string, categoria: string}>  $items
     * @return list<string> animal_ids
     */
    public function handle(Establecimiento $est, User $usuario, array $items): array
    {
        return DB::transaction(function () use ($est, $usuario, $items) {
            $cadena = Cadena::bloquearDeEstablecimiento($est->id);
            $errores = [];
            $estados = [];
            foreach ($items as $i => $item) {
                $estado = DB::table('animales_estado')->where('animal_id', $item['animal_id'])->where('establecimiento_id', $est->id)->lockForUpdate()->first();
                if ($estado === null) {
                    $errores["items.{$i}.animal_id"] = [__('El animal no existe en el establecimiento.')];

                    continue;
                }
                $yaDescartada = DB::table('sugerencias_descartadas')->where('animal_id', $item['animal_id'])->where('categoria_codigo', $item['categoria'])->exists();
                if ($estado->categoria_sugerida_codigo !== $item['categoria'] && ! $yaDescartada) {
                    $errores["items.{$i}.categoria"] = [__('No es la categoría sugerida para este animal (la sugerencia cambió).')];

                    continue;
                }
                $estados[] = [$estado, $yaDescartada];
            }
            if ($errores !== []) {
                throw ApiException::validacion(__('Hay sugerencias que no se pueden descartar.'), $errores);
            }

            foreach ($estados as $i => [$estado, $yaDescartada]) {
                if ($yaDescartada) {
                    continue;
                }
                DB::table('sugerencias_descartadas')->insert([
                    'animal_id' => $estado->animal_id, 'categoria_codigo' => $estado->categoria_sugerida_codigo, 'establecimiento_id' => $est->id,
                    'descartada_por' => $usuario->id, 'descartada_en' => now(),
                ]);
                DB::table('animales_estado')->where('animal_id', $estado->animal_id)->update([
                    'categoria_sugerida_codigo' => null, 'categoria_sugerida_regla_id' => null, 'categoria_sugerida_en' => null,
                ]);
                $this->auditoria->handle($est->id, $usuario->id, 'animal', $estado->animal_id, 'descartar_sugerencia',
                    ['categoria_sugerida' => $estado->categoria_sugerida_codigo], ['categoria_sugerida' => null]);
                $this->cambio->handle($est->id, 'animal', $estado->animal_id, cadena: $cadena);
            }

            return array_map(fn ($it) => $it['animal_id'], $items);
        });
    }
}
