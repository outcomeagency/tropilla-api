<?php

namespace App\Console\Commands;

use App\Domain\Telegram\TelegramApi;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Registra, consulta o borra el webhook del bot (TPA-77) con el token y el secreto del entorno, sin mostrarlos.
 * `php artisan telegram:webhook --url=https://<api>/api/v1/telegram/webhook` · `--info` · `--borrar`.
 */
class TelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook {--url= : URL pública HTTPS del webhook} {--info : Ver el estado del webhook} {--borrar : Borrar el webhook} {--descartar-pendientes : drop_pending_updates}';

    protected $description = 'Registra (setWebhook con secret_token), consulta o borra el webhook del bot de Telegram';

    public function handle(TelegramApi $tg): int
    {
        if (! $tg->configurado()) {
            $this->error('Falta TELEGRAM_BOT_TOKEN.');

            return self::FAILURE;
        }
        try {
            if ($this->option('info')) {
                $i = $tg->llamar('getWebhookInfo', []);
                $this->line(json_encode(array_intersect_key($i, array_flip(['url', 'pending_update_count', 'last_error_date', 'last_error_message', 'max_connections', 'allowed_updates'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }
            if ($this->option('borrar')) {
                $tg->llamar('deleteWebhook', ['drop_pending_updates' => (bool) $this->option('descartar-pendientes')]);
                $this->info('Webhook borrado.');

                return self::SUCCESS;
            }
            $url = (string) $this->option('url');
            $secreto = (string) config('tropilla.telegram.webhook_secret');
            if (! str_starts_with($url, 'https://') || ! preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secreto)) {
                $this->error('Usá --url=https://… y un TELEGRAM_WEBHOOK_SECRET de 16 a 256 caracteres [A-Za-z0-9_-].');

                return self::FAILURE;
            }
            $tg->llamar('setWebhook', ['url' => $url, 'secret_token' => $secreto, 'allowed_updates' => ['message', 'callback_query'],
                'drop_pending_updates' => (bool) $this->option('descartar-pendientes'), 'max_connections' => 20]);
            $tg->llamar('setMyCommands', ['commands' => [
                ['command' => 'ayuda', 'description' => 'Qué puedo hacer'],
                ['command' => 'campo', 'description' => 'Elegir el establecimiento'],
                ['command' => 'avisos', 'description' => 'Activar o pausar avisos'],
                ['command' => 'cancelar', 'description' => 'Descartar lo pendiente'],
                ['command' => 'salir', 'description' => 'Desvincular este chat'],
            ]]);
            $this->info("Webhook registrado en {$url}.");

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage()); // TelegramApi ya reemplazó el token por ***

            return self::FAILURE;
        }
    }
}
