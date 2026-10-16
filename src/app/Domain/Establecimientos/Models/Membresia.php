<?php

namespace App\Domain\Establecimientos\Models;

use App\Domain\Establecimientos\Enums\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Membresía usuario ↔ establecimiento con rol (modelo-datos.md §2.5).
 * Cambiar de rol = revocar y crear otra (queda historia). Un solo dueño activo por establecimiento.
 *
 * @property string $id
 * @property string $establecimiento_id
 * @property string $usuario_id
 * @property Rol $rol
 * @property string $estado
 * @property Carbon $aceptada_en
 * @property Carbon|null $revocada_en
 * @property-read Establecimiento $establecimiento
 * @property-read User $usuario
 */
class Membresia extends Model
{
    use HasUuids;

    protected $table = 'membresias';

    protected $fillable = [
        'establecimiento_id', 'usuario_id', 'rol', 'estado', 'invitado_por', 'aceptada_en',
        'revocada_en', 'revocada_por', 'motivo_revocacion',
    ];

    protected $attributes = ['estado' => 'activa'];

    protected function casts(): array
    {
        return [
            'rol' => Rol::class,
            'aceptada_en' => 'datetime',
            'revocada_en' => 'datetime',
        ];
    }

    /** @param Builder<Membresia> $q */
    public function scopeActivas(Builder $q): void
    {
        $q->where('estado', 'activa');
    }

    public function activa(): bool
    {
        return $this->estado === 'activa';
    }

    /** @return BelongsTo<Establecimiento, $this> */
    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
