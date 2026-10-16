<?php

namespace App\Domain\Establecimientos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Invitación a un establecimiento con rol (modelo-datos.md §2.6). Vence a los 7 días.
 *
 * @property string $id
 * @property string $establecimiento_id
 * @property string|null $email
 * @property string|null $telefono
 * @property string $rol
 * @property Carbon $expira_en
 * @property Carbon|null $aceptada_en
 * @property Carbon|null $revocada_en
 * @property string $creado_por
 * @property-read Establecimiento $establecimiento
 * @property-read User $creador
 */
class Invitacion extends Model
{
    use HasUuids;

    protected $table = 'invitaciones';

    protected $fillable = ['establecimiento_id', 'email', 'telefono', 'rol', 'token_hash', 'expira_en', 'creado_por'];

    protected $hidden = ['token_hash'];

    /** Token en claro: solo existe en memoria al crear (para el mail y la respuesta). */
    public ?string $tokenPlano = null;

    protected function casts(): array
    {
        return ['expira_en' => 'datetime', 'aceptada_en' => 'datetime', 'revocada_en' => 'datetime'];
    }

    public function vigente(): bool
    {
        return $this->aceptada_en === null && $this->revocada_en === null && $this->expira_en->isFuture();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function porToken(string $token): ?self
    {
        /** @var self|null */
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    public function url(): ?string
    {
        return $this->tokenPlano === null ? null : config('tropilla.web_url').'/invitacion/'.$this->tokenPlano;
    }

    /** @return BelongsTo<Establecimiento, $this> */
    public function establecimiento(): BelongsTo
    {
        return $this->belongsTo(Establecimiento::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
