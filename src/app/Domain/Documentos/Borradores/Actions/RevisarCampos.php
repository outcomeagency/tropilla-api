<?php

namespace App\Domain\Documentos\Borradores\Actions;

use App\Domain\Documentos\Borradores\Familias\Familias;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revisión humana campo por campo (TPA-61): cada decisión (`correcto`, `editado` con valor nuevo, `no_legible`, `no_aplica`)
 * queda en `borrador_correcciones` (append-only: quién, cuándo, antes y después) y actualiza la proyección. También permite
 * agregar una fila (vacuna / categoría) que el modelo no vio. Las advertencias se recalculan con los valores revisados.
 */
final class RevisarCampos
{
    /**
     * @param  list<array{ruta: string, estado: string, valor?: mixed}>  $cambios
     */
    public function handle(Establecimiento $est, User $usuario, object $b, array $cambios, ?string $agregarFila, ?int $rowVersion): object
    {
        return DB::transaction(function () use ($est, $usuario, $b, $cambios, $agregarFila, $rowVersion) {
            $b = DB::table('borradores_documento')->where('id', $b->id)->lockForUpdate()->first();
            if ($b->estado !== 'para_revisar') {
                throw new ApiException(409, 'estado_invalido', __('El borrador no está para revisar (:e).', ['e' => $b->estado]));
            }
            if ($rowVersion !== null && $rowVersion !== (int) $b->row_version) {
                throw ApiException::conflictoRowVersion(['row_version' => (int) $b->row_version]);
            }
            $familia = Familias::get($b->familia);
            $campos = json_decode($b->campos, true);
            if ($agregarFila !== null) {
                array_push($campos, ...$familia->filaNueva($agregarFila, $campos));
            }
            $indice = array_flip(array_column($campos, 'ruta'));
            foreach ($cambios as $c) {
                if (! isset($indice[$c['ruta']])) {
                    throw ApiException::validacion(__('Campo inexistente: :r.', ['r' => $c['ruta']]), ["campos.{$c['ruta']}" => [__('No existe.')]]);
                }
                $campo = &$campos[$indice[$c['ruta']]];
                $anterior = $campo['valor'];
                if ($c['estado'] === 'editado') {
                    if (! array_key_exists('valor', $c)) {
                        throw ApiException::validacion(__('Falta el valor corregido.'), ["campos.{$c['ruta']}" => [__('Obligatorio al editar.')]]);
                    }
                    $campo['valor'] = $familia->validarValor($campo, $c['valor']);
                    $campo['sugerido'] = false;
                }
                $campo['estado'] = $c['estado'];
                DB::table('borrador_correcciones')->insert([
                    'borrador_id' => $b->id, 'extraccion_version' => (int) $b->extraccion_vigente, 'ruta' => $c['ruta'], 'estado' => $c['estado'],
                    'valor_anterior' => json_encode($anterior, JSON_UNESCAPED_UNICODE), 'valor' => json_encode($campo['valor'], JSON_UNESCAPED_UNICODE), 'usuario_id' => $usuario->id,
                ]);
                unset($campo);
            }
            $salida = json_decode((string) DB::table('borrador_extracciones')->where('borrador_id', $b->id)->where('version', $b->extraccion_vigente)->value('salida'), true) ?? [];
            DB::table('borradores_documento')->where('id', $b->id)->update([
                'campos' => json_encode($campos, JSON_UNESCAPED_UNICODE), 'advertencias' => json_encode($familia->advertencias($est, $campos, $salida), JSON_UNESCAPED_UNICODE),
                'row_version' => $b->row_version + 1, 'updated_at' => now(),
            ]);

            return DB::table('borradores_documento')->where('id', $b->id)->first();
        });
    }
}
