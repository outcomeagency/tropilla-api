<?php

namespace App\Domain\Hacienda\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Identidad del animal (modelo-datos.md §4.3). Todo lo demás es proyección (`animales_estado`).
 * El id lo genera el cliente y viaja en el payload del evento que lo crea.
 *
 * @property string $id
 * @property string $establecimiento_id
 * @property string $especie_codigo
 * @property string $evento_origen_raiz_id
 * @property string|null $lote_origen_anonimo_id
 */
class Animal extends Model
{
    protected $table = 'animales';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'establecimiento_id', 'especie_codigo', 'evento_origen_raiz_id', 'lote_origen_anonimo_id'];

    /** @return HasOne<AnimalEstado, $this> */
    public function estado(): HasOne
    {
        return $this->hasOne(AnimalEstado::class, 'animal_id');
    }
}
