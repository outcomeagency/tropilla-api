<?php

namespace App\Models;

use App\Domain\Cuentas\Models\Dispositivo;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Identidad del usuario (tabla `usuarios`, modelo-datos.md §2.1).
 * Login por email **o** teléfono. El rol no vive acá: es por establecimiento (membresías).
 *
 * @property string $id
 * @property string $nombre
 * @property string|null $email
 * @property string|null $telefono
 * @property string $idioma
 * @property string|null $pais
 * @property string|null $foto_sha256
 * @property bool $es_staff
 * @property Carbon|null $email_verificado_en
 * @property Carbon|null $bloqueado_en
 * @property Carbon|null $created_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $table = 'usuarios';

    protected $fillable = [
        'nombre', 'email', 'telefono', 'password', 'idioma', 'pais', 'foto_sha256',
        'email_verificado_en', 'telefono_verificado_en', 'ultimo_acceso_en', 'bloqueado_en',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['idioma' => 'es', 'es_staff' => false];

    protected function casts(): array
    {
        return [
            'email_verificado_en' => 'datetime',
            'telefono_verificado_en' => 'datetime',
            'ultimo_acceso_en' => 'datetime',
            'bloqueado_en' => 'datetime',
            'es_staff' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<Dispositivo, $this> */
    public function dispositivos(): HasMany
    {
        return $this->hasMany(Dispositivo::class, 'usuario_id');
    }

    public function emailVerificado(): bool
    {
        return $this->email_verificado_en !== null;
    }

    /** Destino de las notificaciones por mail (columna `email`, citext). */
    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    public function preferredLocale(): string
    {
        return $this->idioma;
    }
}
