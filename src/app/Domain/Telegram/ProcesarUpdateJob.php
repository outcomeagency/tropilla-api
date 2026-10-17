<?php

namespace App\Domain\Telegram;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Procesa en cola un update del webhook (TPA-77): el webhook responde enseguida y Telegram no reintenta. Un solo intento:
 * reintentar un update podría repetir efectos (mensajes, propuestas); las cargas son idempotentes por id igualmente.
 */
class ProcesarUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    /** @param array<string, mixed> $update */
    public function __construct(public readonly array $update) {}

    public function handle(Bot $bot, TelegramApi $tg): void
    {
        try {
            $bot->atender($this->update);
        } catch (Throwable $e) {
            report($e);
            $chat = $this->update['message']['chat']['id'] ?? $this->update['callback_query']['message']['chat']['id'] ?? null;
            if ($chat !== null) {
                try {
                    $tg->enviar((int) $chat, 'Algo falló de nuestro lado y no pude procesar el mensaje. No se registró nada. Probá de nuevo en un rato.');
                } catch (Throwable) {
                }
            }
        }
    }
}
