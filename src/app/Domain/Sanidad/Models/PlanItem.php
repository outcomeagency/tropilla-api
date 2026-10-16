<?php

namespace App\Domain\Sanidad\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ítem del plan sanitario (modelo-datos §7). Si viene de una plantilla guarda código y versión; `editado` = difiere de ella.
 * Es una **sugerencia**: nunca dosis; el aviso veterinario se acepta en el plan.
 *
 * @property string $id
 * @property string $plan_id
 * @property string $establecimiento_id
 * @property string|null $plantilla_codigo
 * @property int|null $plantilla_version
 * @property string $nombre
 * @property string|null $especie_codigo
 * @property string $tipo_evento
 * @property string|null $enfermedad
 * @property string|null $producto_id
 * @property array<string, mixed> $alcance
 * @property array<string, mixed> $disparador
 * @property array<string, mixed>|null $repeticion
 * @property array<string, mixed>|null $condicion
 * @property string|null $nota
 * @property bool $activo
 * @property bool $editado
 * @property int $row_version
 * @property Carbon $created_at
 * @property Carbon|null $archivado_en
 */
class PlanItem extends Model
{
    use HasUuids;

    public const CAMPOS = ['nombre', 'especie_codigo', 'tipo_evento', 'enfermedad', 'producto_id', 'alcance', 'disparador', 'repeticion', 'condicion', 'activo'];

    protected $table = 'plan_items';

    protected $guarded = [];

    protected $attributes = ['row_version' => 1, 'activo' => true, 'editado' => false, 'alcance' => '{}'];

    protected function casts(): array
    {
        return [
            'alcance' => 'array', 'disparador' => 'array', 'repeticion' => 'array', 'condicion' => 'array',
            'activo' => 'boolean', 'editado' => 'boolean', 'row_version' => 'integer', 'plantilla_version' => 'integer',
            'archivado_en' => 'datetime',
        ];
    }
}
