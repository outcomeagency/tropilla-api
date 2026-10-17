<?php

namespace App\Domain\Notificaciones;

use App\Domain\Establecimientos\Enums\Rol;
use App\Domain\Notificaciones\Push\PushDriver;
use App\Domain\Telegram\AvisoTelegramJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Crea notificaciones in-app (siempre) y las empuja por push según preferencias (TPA-53) y por Telegram al chat vinculado
 * (TPA-77, canal `telegram` de las preferencias, en cola). Idempotente por `clave_dedupe`
 * (el mismo aviso no se repite). Destinatarios por rol de membresía activa (functional/04 §11).
 */
final class Notificador
{
    public function __construct(private readonly PushDriver $push) {}

    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $datos
     * @return int notificaciones nuevas
     */
    public function aRoles(string $establecimientoId, array $roles, string $tipo, string $titulo, string $cuerpo, array $datos, string $clave): int
    {
        $usuarios = DB::table('membresias')->where('establecimiento_id', $establecimientoId)->where('estado', 'activa')
            ->whereIn('rol', $roles)->pluck('usuario_id')->unique();
        $n = 0;
        foreach ($usuarios as $u) {
            $n += $this->aUsuario($u, $establecimientoId, $tipo, $titulo, $cuerpo, $datos, $clave) ? 1 : 0;
        }

        return $n;
    }

    /** Roles con una ability (para alertas que siguen la matriz de permisos). @return list<string> */
    public static function rolesCon(string $ability): array
    {
        return Rol::MATRIZ[$ability] ?? [];
    }

    /** @param array<string, mixed> $datos */
    public function aUsuario(string $usuarioId, ?string $establecimientoId, string $tipo, string $titulo, string $cuerpo, array $datos, string $clave): bool
    {
        $id = (string) Str::uuid7();
        $insertada = DB::table('notificaciones')->insertOrIgnore([
            'id' => $id, 'usuario_id' => $usuarioId, 'establecimiento_id' => $establecimientoId, 'tipo' => $tipo, 'titulo' => $titulo, 'cuerpo' => $cuerpo,
            'datos' => json_encode((object) $datos), 'canal' => 'in_app', 'clave_dedupe' => $usuarioId.'|'.$clave, 'created_at' => now(),
        ]);
        if ($insertada === 0) {
            return false;
        }
        if ($this->preferencia($usuarioId, $establecimientoId, $tipo, 'push')) {
            $enviado = false;
            foreach (DB::table('dispositivos')->where('usuario_id', $usuarioId)->whereNull('revocado_en')->whereNotNull('fcm_token')->get(['id', 'fcm_token']) as $d) {
                try {
                    if ($this->push->enviar($d->fcm_token, $titulo, $cuerpo, ['tipo' => $tipo, 'notificacion_id' => $id, 'establecimiento_id' => (string) $establecimientoId])) {
                        $enviado = true;
                    } else {
                        DB::table('dispositivos')->where('id', $d->id)->update(['fcm_token' => null]);
                    }
                } catch (Throwable $e) {
                    Log::warning('push: no se pudo enviar', ['dispositivo' => $d->id, 'error' => $e->getMessage()]);
                }
            }
            if ($enviado) {
                DB::table('notificaciones')->where('id', $id)->update(['enviada_en' => now(), 'canal' => 'push']);
            }
        }
        if ($this->preferencia($usuarioId, $establecimientoId, $tipo, 'telegram')
            && DB::table('telegram_vinculos')->where('usuario_id', $usuarioId)->whereNull('desvinculado_en')->where('avisos', true)->exists()) {
            AvisoTelegramJob::dispatch($id)->afterCommit();
        }

        return true;
    }

    /** Preferencia efectiva: la del establecimiento, si no la general (sin establecimiento), si no el default (push y telegram sí, email y resumen no). */
    public function preferencia(string $usuarioId, ?string $establecimientoId, string $tipo, string $canal): bool
    {
        $p = DB::table('preferencias_notificacion')->where('usuario_id', $usuarioId)->where('tipo', $tipo)
            ->where(fn ($q) => $q->where('establecimiento_id', $establecimientoId)->orWhereNull('establecimiento_id'))
            ->orderByRaw('establecimiento_id IS NULL')->first();

        return $p ? (bool) $p->{$canal} : in_array($canal, ['push', 'telegram'], true);
    }
}
