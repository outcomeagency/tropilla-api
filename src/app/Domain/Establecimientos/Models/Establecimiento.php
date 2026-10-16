<?php

namespace App\Domain\Establecimientos\Models;

use App\Casts\PgTextArray;
use App\Casts\PuntoGeografico;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Establecimiento (modelo-datos.md §2.4). Unidad de tenencia: todo dato de campo cuelga de acá.
 * RENSPA y ubicación exacta son **privados** (solo `establecimiento.ver_privados`).
 *
 * @property string $id
 * @property string $productor_id
 * @property string $nombre
 * @property string $provincia_codigo
 * @property string $departamento_codigo
 * @property string|null $localidad
 * @property array{lat: string, lon: string}|null $ubicacion
 * @property string|null $renspa
 * @property string|null $superficie_ha
 * @property list<string> $actividades
 * @property list<string> $especies
 * @property array<string, mixed> $zonas_sanitarias
 * @property list<string> $instalaciones
 * @property string|null $historia
 * @property string $zona_horaria
 * @property int $row_version
 * @property Carbon|null $created_at
 * @property Carbon|null $archivado_en
 * @property-read Productor $productor
 */
class Establecimiento extends Model
{
    use HasUuids;

    protected $table = 'establecimientos';

    protected $fillable = [
        'productor_id', 'nombre', 'provincia_codigo', 'departamento_codigo', 'localidad', 'ubicacion',
        'renspa', 'superficie_ha', 'actividades', 'especies', 'zonas_sanitarias', 'instalaciones',
        'historia', 'zona_horaria',
    ];

    protected $attributes = ['zonas_sanitarias' => '{}', 'row_version' => 1, 'zona_horaria' => 'America/Argentina/Buenos_Aires'];

    protected function casts(): array
    {
        return [
            'ubicacion' => PuntoGeografico::class,
            'actividades' => PgTextArray::class,
            'especies' => PgTextArray::class,
            'instalaciones' => PgTextArray::class,
            'zonas_sanitarias' => 'array',
            'row_version' => 'integer',
            'archivado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Productor, $this> */
    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class);
    }

    /** @return HasMany<Membresia, $this> */
    public function membresias(): HasMany
    {
        return $this->hasMany(Membresia::class);
    }
}
