<?php

namespace App\Domain\Hacienda\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Lote de manejo (modelo-datos.md §4.2). Un lote no mezcla especies. Potrero y composición actuales
 * no se editan acá: salen de eventos (`lotes_estado`, `lote_conteos`).
 *
 * @property string $id
 * @property string $establecimiento_id
 * @property string $nombre
 * @property string $especie_codigo
 * @property string|null $proposito
 * @property bool $anonimo
 * @property string|null $notas
 * @property int $row_version
 * @property Carbon|null $archivado_en
 */
class Lote extends Model
{
    use HasUuids;

    protected $table = 'lotes';

    protected $fillable = ['id', 'establecimiento_id', 'nombre', 'especie_codigo', 'proposito', 'anonimo', 'notas', 'creado_por'];

    protected $attributes = ['anonimo' => false, 'row_version' => 1];

    protected function casts(): array
    {
        return ['anonimo' => 'boolean', 'row_version' => 'integer', 'archivado_en' => 'datetime'];
    }
}
