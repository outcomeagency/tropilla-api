<?php

namespace App\Domain\Telegram;

use App\Domain\Establecimientos\Models\Establecimiento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Quién habla con el bot: vínculo activo, usuario y establecimiento activo. El bot actúa **con los permisos del usuario
 * vinculado** (mismas Policies que la API: membresía activa + rol); cada acción vuelve a chequearlos.
 */
final class Contexto
{
    public function __construct(
        public readonly object $vinculo,
        public readonly User $usuario,
        public readonly int $chatId,
        public readonly ?Establecimiento $est,
    ) {}

    /**
     * Contexto de un vínculo activo con el establecimiento indicado (o el activo del vínculo). null si el usuario ya no
     * existe o está bloqueado. Un establecimiento sin membresía activa queda en null (se pide `/campo`).
     */
    public static function desdeVinculo(object $vinculo, ?string $establecimientoId = null): ?self
    {
        $usuario = User::query()->find($vinculo->usuario_id);
        if ($usuario === null || $usuario->bloqueado_en !== null) {
            return null;
        }
        $estId = $establecimientoId ?? $vinculo->establecimiento_id;
        $est = $estId ? Establecimiento::query()->find($estId) : null;
        if ($est !== null && ! Gate::forUser($usuario)->allows('establecimiento.ver', $est)) {
            $est = null;
        }

        return new self($vinculo, $usuario, (int) $vinculo->chat_id, $est);
    }

    /**
     * Corre `$fn` con el usuario vinculado como usuario autenticado (Policies y Actions que leen el usuario actual) y
     * después lo olvida: el worker de la cola es de larga vida y atiende a muchos usuarios.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function como(User $usuario, callable $fn): mixed
    {
        $guard = Auth::guard();
        $previo = $guard->hasUser() ? $guard->user() : null;
        $guard->setUser($usuario);
        try {
            return $fn();
        } finally {
            $previo !== null ? $guard->setUser($previo) : $guard->forgetUser();
        }
    }

    public function puede(string $ability): bool
    {
        return $this->est !== null && Gate::forUser($this->usuario)->allows($ability, $this->est);
    }

    public function hoy(): string
    {
        return Carbon::now($this->est?->zona_horaria ?? 'America/Argentina/Buenos_Aires')->toDateString();
    }

    public function bitacora(string $direccion, string $tipo, ?string $accion = null, ?string $entidadTipo = null, ?string $entidadId = null, array $detalle = []): void
    {
        $v = clone $this->vinculo;
        $v->establecimiento_id = $this->est?->id;
        Bitacora::registrar($this->chatId, $direccion, $tipo, $v, $accion, $entidadTipo, $entidadId, $detalle);
    }
}
