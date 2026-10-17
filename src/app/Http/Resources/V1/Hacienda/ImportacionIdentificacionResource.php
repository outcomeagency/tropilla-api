<?php

namespace App\Http\Resources\V1\Hacienda;

use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** `ImportacionIdentificaciones` (TPA-63). `detalle`: suma las filas con su estado del teléfono y del servidor. */
final class ImportacionIdentificacionResource
{
    public const AVISO = 'Registro interno de TROPILLA. No reemplaza la declaración en el sistema oficial de SENASA (SIGSA).';

    /** @return array<string, mixed> */
    public static function make(object $i, bool $detalle = false): array
    {
        $fecha = fn ($t) => $t === null ? null : Formato::fechaHora(Carbon::parse($t));
        $out = [
            'id' => $i->id, 'especie' => $i->especie_codigo, 'fecha' => $i->fecha, 'motivo' => $i->motivo, 'origen' => $i->origen,
            'dispositivo_tipo' => $i->dispositivo_tipo, 'lector' => $i->lector, 'archivo_nombre' => $i->archivo_nombre, 'documento_id' => $i->documento_id,
            'estado' => $i->estado, 'resumen' => (object) json_decode($i->resumen, true), 'reglas' => json_decode($i->reglas, true),
            'aviso' => __(self::AVISO), 'creado_por' => $i->creado_por, 'created_at' => $fecha($i->created_at), 'updated_at' => $fecha($i->updated_at),
            'confirmada_en' => $fecha($i->confirmada_en),
        ];
        if ($detalle) {
            $out['filas'] = DB::table('identificacion_importacion_filas as f')->leftJoin('animales_estado as a', 'a.animal_id', '=', 'f.animal_id')
                ->where('f.importacion_id', $i->id)->orderBy('f.numero')->get(['f.*', 'a.identificacion_principal'])
                ->map(fn ($f) => [
                    'numero' => (int) $f->numero, 'rfid' => $f->rfid, 'visual' => $f->visual, 'leido' => $f->leido, 'origen' => $f->origen, 'fecha' => $f->fecha,
                    'animal_id' => $f->animal_id, 'animal_identificacion' => $f->identificacion_principal, 'estado' => $f->estado,
                    'estado_servidor' => $f->estado_servidor, 'mensajes' => json_decode($f->mensajes, true), 'evento_id' => $f->evento_id,
                ])->all();
        }

        return $out;
    }
}
