<?php

namespace App\Domain\Hacienda\Models;

use App\Domain\Establecimientos\Models\Establecimiento;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Potrero (modelo-datos.md §4.1). Maestro mutable con `row_version`; nunca se borra (se archiva).
 * `poligono` es geography(Polygon) (fase 3 / EUDR): entra y sale como GeoJSON.
 *
 * @property string $id
 * @property string $establecimiento_id
 * @property string $nombre
 * @property string|null $superficie_ha
 * @property string|null $ambiente
 * @property string|null $recurso_forrajero
 * @property bool $agua
 * @property bool $revolcadero
 * @property string|null $notas
 * @property int $row_version
 * @property Carbon|null $archivado_en
 */
class Potrero extends Model
{
    use HasUuids;

    protected $table = 'potreros';

    protected $fillable = [
        'id', 'establecimiento_id', 'nombre', 'superficie_ha', 'ambiente', 'recurso_forrajero', 'agua',
        'revolcadero', 'notas',
    ];

    protected $hidden = ['poligono'];

    protected $attributes = ['agua' => false, 'revolcadero' => false, 'row_version' => 1];

    protected function casts(): array
    {
        return [
            'agua' => 'boolean',
            'revolcadero' => 'boolean',
            'row_version' => 'integer',
            'archivado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Establecimiento, $this> */
    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }
}
