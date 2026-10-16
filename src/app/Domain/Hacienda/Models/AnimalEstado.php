<?php

namespace App\Domain\Hacienda\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Proyección del estado del animal (modelo-datos.md §6.1). **Nunca se edita a mano**: la escribe el
 * proyector desde los eventos vigentes. Los valores que dependen de «hoy» (edad, retiro vigente) se calculan al leer.
 *
 * @property string $animal_id
 * @property string $establecimiento_id
 * @property string $especie_codigo
 * @property string $sexo
 * @property string $categoria_codigo
 * @property string $estado
 */
class AnimalEstado extends Model
{
    protected $table = 'animales_estado';

    protected $primaryKey = 'animal_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cruza' => 'array', 'fecha_nacimiento' => 'date', 'nacimiento_estimado' => 'boolean', 'castrado' => 'boolean',
            'baja_en' => 'date', 'peso_fecha' => 'date', 'peso_anterior_fecha' => 'date', 'cc_fecha' => 'date',
            'destetado' => 'boolean', 'destete_fecha' => 'date', 'ultimo_parto_fecha' => 'date', 'servicio_fecha' => 'date',
            'tacto_fecha' => 'date', 'fecha_probable_parto' => 'date', 'reactor' => 'boolean', 'reactor_desde' => 'date',
            'retiro_carne_hasta' => 'date', 'retiro_leche_hasta' => 'date', 'ultimo_evento_ocurrido_en' => 'datetime',
            'categoria_sugerida_en' => 'datetime', 'actualizado_en' => 'datetime', 'partos' => 'integer', 'eventos_count' => 'integer',
        ];
    }
}
