<?php

namespace App\Domain\Catalogos\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Departamento INDEC (modelo-datos.md §3.1). `garrapata_sugerida` alimenta la zona sanitaria sugerida.
 *
 * @property string $codigo
 * @property string $provincia_codigo
 * @property string $provincia_nombre
 * @property string $nombre
 * @property string|null $garrapata_sugerida
 */
class GeoDepartamento extends Model
{
    protected $table = 'geo_departamentos';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['codigo', 'provincia_codigo', 'provincia_nombre', 'nombre', 'garrapata_sugerida'];
}
