<?php

namespace App\Domain\Integridad\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cadena de integridad (una por establecimiento o productor). Es la fila que se bloquea
 * (`FOR UPDATE`) para encadenar y para asignar `cambios.seq` (sync-e-integridad.md §5.3, S7).
 *
 * @property string $id
 * @property string|null $establecimiento_id
 * @property string|null $productor_id
 * @property int $ultimo_seq
 * @property string $ultimo_eslabon
 * @property int $ultimo_seq_cambios
 */
class Cadena extends Model
{
    use HasUuids;

    protected $table = 'cadenas';

    public $timestamps = false;

    protected $fillable = ['establecimiento_id', 'productor_id'];

    protected function casts(): array
    {
        return ['ultimo_seq' => 'integer', 'ultimo_seq_cambios' => 'integer'];
    }

    /** Bloquea la cadena del establecimiento hasta el fin de la transacción. */
    public static function bloquearDeEstablecimiento(string $establecimientoId): self
    {
        /** @var self */
        return self::query()->where('establecimiento_id', $establecimientoId)->lockForUpdate()->firstOrFail();
    }

    /** Cadena del **productor** (consentimientos a organizaciones, TPA-51): se crea la primera vez y se bloquea. */
    public static function bloquearDeProductor(string $productorId): self
    {
        if (! self::query()->where('productor_id', $productorId)->exists()) {
            DB::table('cadenas')->insertOrIgnore(['id' => (string) Str::uuid7(), 'productor_id' => $productorId, 'created_at' => now()]);
        }

        /** @var self */
        return self::query()->where('productor_id', $productorId)->lockForUpdate()->firstOrFail();
    }
}
