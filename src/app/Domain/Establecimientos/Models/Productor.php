<?php

namespace App\Domain\Establecimientos\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Productor (modelo-datos.md §2.3). CUIT privado siempre: cifrado (`cuit_cifrado`) +
 * HMAC con pepper (`cuit_hash`) para búsqueda exacta sin descifrar.
 *
 * @property string $id
 * @property string $tipo
 * @property string $nombre
 * @property string|null $cuit_cifrado
 * @property array<string, mixed> $contacto
 * @property list<array<string, mixed>> $asociaciones
 * @property string|null $logo_sha256
 * @property int $row_version
 */
class Productor extends Model
{
    use HasUuids;

    protected $table = 'productores';

    protected $fillable = ['tipo', 'nombre', 'contacto', 'asociaciones', 'logo_sha256', 'creado_por'];

    protected $hidden = ['cuit_cifrado', 'cuit_hash'];

    protected $attributes = ['contacto' => '{}', 'asociaciones' => '[]', 'row_version' => 1];

    protected function casts(): array
    {
        return [
            'cuit_cifrado' => 'encrypted',
            'contacto' => 'array',
            'asociaciones' => 'array',
            'row_version' => 'integer',
        ];
    }

    /** CUIT normalizado a 11 dígitos (o null). */
    public function asignarCuit(?string $cuit): void
    {
        $digitos = $cuit === null ? null : preg_replace('/\D/', '', $cuit);
        $this->cuit_cifrado = $digitos ?: null;
        $this->cuit_hash = $digitos ? self::hashCuit($digitos) : null;
    }

    public function cuit(): ?string
    {
        return $this->cuit_cifrado;
    }

    public static function hashCuit(string $digitos): string
    {
        return hash_hmac('sha256', $digitos, (string) config('tropilla.cuit_pepper'));
    }

    /** @return HasMany<Establecimiento, $this> */
    public function establecimientos(): HasMany
    {
        return $this->hasMany(Establecimiento::class);
    }
}
