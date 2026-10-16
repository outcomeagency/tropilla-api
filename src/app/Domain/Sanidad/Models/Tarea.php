<?php

namespace App\Domain\Sanidad\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tarea sanitaria (modelo-datos §7). `vencida` no se guarda: es `pendiente` con `vence_en` < hoy (zona del establecimiento).
 *
 * @property string $id
 * @property string $establecimiento_id
 * @property string|null $plan_item_id
 * @property string|null $clave_ocurrencia
 * @property string $titulo
 * @property string $tipo_evento
 * @property string|null $enfermedad
 * @property string|null $producto_id
 * @property array<string, mixed> $alcance
 * @property Carbon|null $fecha_desde
 * @property Carbon|null $fecha_hasta
 * @property Carbon $vence_en
 * @property string $estado
 * @property string|null $evento_raiz_id
 * @property int|null $cabezas_estimadas
 * @property bool $reprogramada
 * @property string|null $omitida_motivo
 * @property int $row_version
 */
class Tarea extends Model
{
    use HasUuids;

    protected $table = 'tareas';

    protected $guarded = [];

    protected $attributes = ['row_version' => 1, 'estado' => 'pendiente', 'alcance' => '{}', 'reprogramada' => false];

    protected function casts(): array
    {
        return [
            'alcance' => 'array', 'fecha_desde' => 'date', 'fecha_hasta' => 'date', 'vence_en' => 'date',
            'reprogramada' => 'boolean', 'row_version' => 'integer', 'cabezas_estimadas' => 'integer',
            'omitida_en' => 'datetime', 'hecha_en' => 'datetime',
        ];
    }

    public function estadoVisible(string $hoy): string
    {
        return $this->estado === 'pendiente' && $this->vence_en->toDateString() < $hoy ? 'vencida' : $this->estado;
    }
}
