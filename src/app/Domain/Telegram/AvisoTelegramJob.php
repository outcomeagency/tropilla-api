<?php

namespace App\Domain\Telegram;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Canal Telegram del Notificador (TPA-77): manda la notificación al chat vinculado del usuario si sigue vinculado y con
 * avisos activos. Si Telegram dice que el usuario bloqueó el bot, se desvincula. Queda en la bitácora del bot.
 */
class AvisoTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly string $notificacionId) {}

    public function handle(TelegramApi $tg, Vinculos $vinculos): void
    {
        $n = DB::table('notificaciones')->where('id', $this->notificacionId)->first();
        $v = $n ? Vinculos::deUsuario($n->usuario_id) : null;
        if ($n === null || $v === null || ! $v->avisos || ! $tg->configurado()) {
            return;
        }
        $campo = $n->establecimiento_id ? DB::table('establecimientos')->where('id', $n->establecimiento_id)->value('nombre') : null;
        try {
            $tg->enviar((int) $v->chat_id, $n->titulo.($campo ? " · {$campo}" : '')."\n".$n->cuerpo);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), ' 403:')) {
                $vinculos->desvincular($v, 'sistema'); // bloqueó el bot o borró el chat

                return;
            }
            throw $e;
        }
        Bitacora::registrar((int) $v->chat_id, 'saliente', 'aviso', $v, 'aviso_enviado', 'notificacion', $n->id, ['tipo' => $n->tipo]);
    }
}
