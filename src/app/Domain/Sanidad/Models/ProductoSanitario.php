<?php

namespace App\Domain\Sanidad\Models;

use App\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Producto sanitario del establecimiento (modelo-datos.md §7). Maestro con `row_version`; se archiva, no se borra.
 * Los retiros se copian al payload del evento al aplicar (snapshot): cambiar el producto no reescribe la historia.
 *
 * @property string $id
 * @property string $establecimiento_id
 * @property string $nombre_comercial
 * @property string|null $principio_activo
 * @property string $tipo
 * @property string|null $via
 * @property string|null $dosis_declarada
 * @property int|null $retiro_carne_dias
 * @property int|null $retiro_leche_dias
 * @property list<string> $especies
 * @property list<string> $enfermedades
 * @property string|null $notas
 * @property string|null $sugerido_codigo
 * @property int $row_version
 * @property Carbon|null $archivado_en
 */
class ProductoSanitario extends Model
{
    use HasUuids;

    public const TIPOS = ['vacuna', 'antiparasitario_interno', 'antiparasitario_externo', 'antibiotico', 'hormona', 'otro'];

    public const VIAS = ['subcutanea', 'intramuscular', 'oral', 'pour_on', 'intranasal', 'otra'];

    public const CAMPOS = ['nombre_comercial', 'principio_activo', 'tipo', 'via', 'dosis_declarada', 'retiro_carne_dias',
        'retiro_leche_dias', 'especies', 'enfermedades', 'notas', 'sugerido_codigo'];

    protected $table = 'productos_sanitarios';

    protected $fillable = ['id', 'establecimiento_id', ...self::CAMPOS];

    protected $attributes = ['row_version' => 1, 'especies' => '{}', 'enfermedades' => '{}'];

    protected function casts(): array
    {
        return [
            'especies' => PgTextArray::class,
            'enfermedades' => PgTextArray::class,
            'retiro_carne_dias' => 'integer',
            'retiro_leche_dias' => 'integer',
            'row_version' => 'integer',
            'archivado_en' => 'datetime',
        ];
    }
}
