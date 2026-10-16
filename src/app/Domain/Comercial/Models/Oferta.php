<?php

namespace App\Domain\Comercial\Models;

use App\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Oferta de animales o lote (modelo-datos §9.4). Maestro mutable; lo que ve el comprador es la **última publicación**
 * (`oferta_publicaciones`, append-only y hasheada).
 *
 * @property string $id
 * @property string $establecimiento_id
 * @property string $codigo_publico
 * @property string $tipo
 * @property string $especie_codigo
 * @property string|null $lote_id
 * @property int $cantidad
 * @property string $estado
 * @property array<string, mixed> $mostrar
 * @property array<string, mixed> $condiciones
 * @property array<string, mixed> $textos
 * @property list<string> $categorias
 * @property list<string> $razas
 * @property int|null $publicacion_vigente
 * @property int $row_version
 * @property Carbon|null $publicada_en
 */
class Oferta extends Model
{
    use HasUuids;

    protected $table = 'ofertas';

    protected $guarded = [];

    protected $attributes = ['mostrar' => '{}', 'condiciones' => '{}', 'textos' => '{}', 'avisos' => '[]', 'estado' => 'borrador', 'row_version' => 1];

    protected function casts(): array
    {
        return [
            'mostrar' => 'array', 'condiciones' => 'array', 'textos' => 'array', 'avisos' => 'array',
            'categorias' => PgTextArray::class, 'razas' => PgTextArray::class, 'fotos' => PgTextArray::class,
            'publicada_en' => 'datetime', 'retirada_en' => 'datetime', 'row_version' => 'integer', 'cantidad' => 'integer',
        ];
    }

    /** @return list<string> */
    public function animalIds(): array
    {
        return DB::table('oferta_animales')->where('oferta_id', $this->id)->orderBy('animal_id')->pluck('animal_id')->all();
    }
}
