<?php

namespace App\Domain\Hacienda\Identificacion;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PUT idempotente de una importación de identificaciones armada en el teléfono (TPA-63). Guarda todas las filas con
 * la reverificación del servidor; nunca borra filas (las duplicadas, inválidas y sin conciliar quedan). Una vez
 * `confirmada` no vuelve a `abierta` y la cabecera no cambia: solo se actualizan las filas (p. ej. una resuelta después).
 */
final class GuardarImportacionIdentificaciones
{
    public function __construct(private readonly ConciliadorIdentificaciones $conciliador) {}

    /**
     * @param  array<string, mixed>  $d  validado por GuardarImportacionIdentificacionesRequest
     * @return array{0: object, 1: bool} [importación, creada]
     */
    public function handle(Establecimiento $est, User $usuario, string $id, array $d, ?string $dispositivoId): array
    {
        return DB::transaction(function () use ($est, $usuario, $id, $d, $dispositivoId) {
            $actual = DB::table('identificacion_importaciones')->where('id', $id)->lockForUpdate()->first();
            if ($actual !== null && $actual->establecimiento_id !== $est->id) {
                throw ApiException::noEncontrado();
            }
            $filas = collect($d['filas'])->sortBy('numero')->values()->all();
            if ($actual !== null) {
                $previas = DB::table('identificacion_importacion_filas')->where('importacion_id', $id)->pluck('numero')->all();
                $faltan = array_diff($previas, array_column($filas, 'numero'));
                if ($faltan !== []) {
                    throw ApiException::validacion(__('Las filas de una importación no se borran: marcalas como omitidas.'), ['filas' => array_map(fn ($n) => __('Falta la fila :n.', ['n' => $n]), array_values($faltan))]);
                }
            }
            $cabecera = $actual !== null && $actual->estado === 'confirmada'
                ? (array) $actual
                : array_intersect_key($d, array_flip(['especie', 'fecha', 'motivo', 'origen', 'dispositivo_tipo', 'lector', 'archivo_nombre', 'documento_id', 'estado']))
                    + ['dispositivo_tipo' => null, 'lector' => null, 'archivo_nombre' => null, 'documento_id' => null];
            $especie = $cabecera['especie_codigo'] ?? $cabecera['especie'];
            $fecha = (string) $cabecera['fecha'];

            $conciliadas = $this->conciliador->conciliar($est->id, $especie, array_map(fn ($f) => [
                'numero' => (int) $f['numero'], 'rfid' => $f['rfid'] ?? null, 'visual' => $f['visual'] ?? null, 'animal_id' => $f['animal_id'] ?? null,
                'evento_id' => $f['evento_id'] ?? null, 'estado' => $f['estado'], 'leido' => $f['leido'] ?? null, 'origen' => $f['origen'], 'fecha' => $f['fecha'] ?? null,
            ], $filas));
            $resumen = ['total' => count($conciliadas)];
            foreach ($conciliadas as $f) {
                $resumen[$f['estado_servidor']] = ($resumen[$f['estado_servidor']] ?? 0) + 1;
            }

            $valores = [
                'especie_codigo' => $especie, 'fecha' => $fecha, 'motivo' => $cabecera['motivo'], 'origen' => $cabecera['origen'],
                'dispositivo_tipo' => $cabecera['dispositivo_tipo'], 'lector' => $cabecera['lector'], 'archivo_nombre' => $cabecera['archivo_nombre'],
                'documento_id' => $cabecera['documento_id'], 'estado' => $cabecera['estado'],
                'resumen' => json_encode($resumen), 'reglas' => json_encode(ReglasIdentificacion::para($especie, $fecha, $est->id), JSON_UNESCAPED_UNICODE),
                'confirmada_en' => $cabecera['estado'] === 'confirmada' ? ($actual->confirmada_en ?? now()) : null, 'updated_at' => now(),
            ];
            if ($actual === null) {
                DB::table('identificacion_importaciones')->insert($valores + [
                    'id' => $id, 'establecimiento_id' => $est->id, 'creado_por' => $usuario->id, 'dispositivo_id' => $dispositivoId, 'created_at' => now(),
                ]);
            } else {
                DB::table('identificacion_importaciones')->where('id', $id)->update($valores);
                DB::table('identificacion_importacion_filas')->where('importacion_id', $id)->delete();
            }
            foreach (array_chunk($conciliadas, 500) as $lote) {
                DB::table('identificacion_importacion_filas')->insert(array_map(fn ($f) => [
                    'importacion_id' => $id, 'numero' => $f['numero'], 'rfid' => $f['rfid'], 'visual' => $f['visual'], 'leido' => $f['leido'],
                    'origen' => $f['origen'], 'fecha' => $f['fecha'], 'animal_id' => $f['animal_id'], 'estado' => $f['estado'],
                    'estado_servidor' => $f['estado_servidor'], 'mensajes' => json_encode($f['mensajes'], JSON_UNESCAPED_UNICODE), 'evento_id' => $f['evento_id'],
                ], $lote));
            }

            return [DB::table('identificacion_importaciones')->where('id', $id)->first(), $actual === null];
        });
    }
}
