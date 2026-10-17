<?php

namespace App\Http\Requests\V1\Hacienda;

use App\Domain\Hacienda\Identificacion\ConciliadorIdentificaciones;
use App\Rules\Reglas;
use Illuminate\Foundation\Http\FormRequest;

/** ImportacionIdentificacionesInput (TPA-63): cabecera + todas las filas leídas, también las que no se van a registrar. */
class ImportacionIdentificacionesRequest extends FormRequest
{
    public const MOTIVOS = ['destete', 'primer_movimiento', 'regularizacion', 'reposicion', 'ingreso', 'otro'];

    public const ORIGENES = ['lector', 'archivo', 'escaner', 'manual', 'planilla'];

    public const DISPOSITIVOS = ['boton', 'tarjeta', 'bolo', 'inyectable', 'otro'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fecha = ['nullable', 'date_format:Y-m-d'];

        return [
            'especie' => ['required', 'in:BOV,BUB'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'in:'.implode(',', self::MOTIVOS)],
            'origen' => ['required', 'in:'.implode(',', self::ORIGENES)],
            'dispositivo_tipo' => ['nullable', 'in:'.implode(',', self::DISPOSITIVOS)],
            'lector' => ['nullable', 'string', 'max:120'],
            'archivo_nombre' => ['nullable', 'string', 'max:200'],
            'documento_id' => ['nullable', Reglas::UUID],
            'estado' => ['required', 'in:abierta,confirmada'],
            'filas' => ['present', 'array', 'max:2000'],
            'filas.*.numero' => ['required', 'integer', 'min:1', 'distinct'],
            'filas.*.rfid' => ['nullable', 'string', 'max:64'],
            'filas.*.visual' => ['nullable', 'string', 'max:64'],
            'filas.*.leido' => ['nullable', 'string', 'max:200'],
            'filas.*.origen' => ['required', 'in:'.implode(',', self::ORIGENES)],
            'filas.*.fecha' => $fecha,
            'filas.*.animal_id' => ['nullable', Reglas::UUID],
            'filas.*.estado' => ['required', 'in:'.implode(',', ConciliadorIdentificaciones::ESTADOS)],
            'filas.*.evento_id' => ['nullable', Reglas::UUID],
        ];
    }
}
