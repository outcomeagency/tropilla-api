<?php

namespace App\Domain\Cuentas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dispositivo de la app de campo (modelo-datos.md §2.2). El id lo genera el
 * teléfono al instalar (UUIDv7) y viaja en los sobres como `dispositivo_id`.
 *
 * @property string $id
 * @property string $usuario_id
 * @property string $plataforma
 * @property int|null $token_id
 * @property Carbon|null $revocado_en
 */
class Dispositivo extends Model
{
    protected $table = 'dispositivos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'usuario_id', 'plataforma', 'nombre', 'app_version', 'fcm_token', 'token_id',
        'ultimo_push_en', 'ultimo_pull_en', 'revocado_en',
    ];

    protected function casts(): array
    {
        return [
            'ultimo_push_en' => 'datetime',
            'ultimo_pull_en' => 'datetime',
            'revocado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
