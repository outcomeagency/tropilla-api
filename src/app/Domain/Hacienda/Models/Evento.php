<?php

namespace App\Domain\Hacienda\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Evento de dominio (modelo-datos.md §4.4). **Append-only**: cada versión es una fila; el modelo
 * bloquea update/delete y la base también (trigger `tropilla_inmutable`). Corregir = versión nueva.
 *
 * @property string $id
 * @property string $establecimiento_id
 * @property string $evento_raiz_id
 * @property int $version
 * @property string $accion
 * @property string|null $corrige_evento_id
 * @property string|null $motivo
 * @property bool $en_revision
 * @property string $tipo
 * @property int $tipo_version
 * @property string $sujeto_tipo
 * @property array<string, mixed> $payload
 * @property Carbon $ocurrido_en
 * @property string $ocurrido_precision
 * @property Carbon $creado_en
 * @property Carbon $registrado_en
 * @property string $autor_id
 * @property string|null $dispositivo_id
 * @property string $origen
 * @property string $hash
 * @property string|null $hash_anterior
 * @property int $registro_integridad_id
 */
class Evento extends Model
{
    protected $table = 'eventos';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'conteo' => 'array', 'en_revision' => 'boolean', 'version' => 'integer',
            'tipo_version' => 'integer', 'ocurrido_en' => 'datetime', 'creado_en' => 'datetime', 'registrado_en' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('eventos es append-only: corregir = versión nueva.'));
        static::deleting(fn () => throw new LogicException('eventos es append-only: nada se borra.'));
    }

    /** Sobre tal como se guardó (texto JSON): para recalcular la huella sin pasar por el cast `array`. */
    public function sobreCrudo(): string
    {
        return (string) DB::table('eventos')->where('id', $this->id)->selectRaw('sobre::text as s')->value('s');
    }

    /** @return BelongsTo<User, $this> */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }
}
