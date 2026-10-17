<?php

namespace App\Domain\Telegram;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cliente mínimo de la Bot API de Telegram (TPA-77). El token va en la URL (así lo define Telegram): por eso ningún
 * error que salga de acá lo incluye (se reemplaza por `***`) y nunca se loguea la URL.
 */
class TelegramApi
{
    public function __construct(private readonly ?string $token, private readonly string $base = 'https://api.telegram.org') {}

    public static function desdeConfig(): self
    {
        return new self(config('tropilla.telegram.token'), rtrim((string) config('tropilla.telegram.api_url'), '/'));
    }

    public function configurado(): bool
    {
        return is_string($this->token) && $this->token !== '';
    }

    /**
     * @param  array<string, mixed>|null  $teclado  reply_markup (inline_keyboard o keyboard)
     * @return int|null message_id
     */
    public function enviar(int $chatId, string $texto, ?array $teclado = null): ?int
    {
        $r = $this->llamar('sendMessage', array_filter([
            'chat_id' => $chatId, 'text' => self::recortar($texto), 'reply_markup' => $teclado, 'link_preview_options' => ['is_disabled' => true],
        ], fn ($v) => $v !== null));

        return isset($r['message_id']) ? (int) $r['message_id'] : null;
    }

    /** @param array<string, mixed>|null $teclado */
    public function editar(int $chatId, int $mensajeId, string $texto, ?array $teclado = null): void
    {
        try {
            $this->llamar('editMessageText', array_filter([
                'chat_id' => $chatId, 'message_id' => $mensajeId, 'text' => self::recortar($texto), 'reply_markup' => $teclado ?? ['inline_keyboard' => []],
                'link_preview_options' => ['is_disabled' => true],
            ], fn ($v) => $v !== null));
        } catch (RuntimeException $e) {
            if (! str_contains($e->getMessage(), 'message is not modified')) {
                throw $e;
            }
        }
    }

    public function responderCallback(string $callbackId, ?string $texto = null): void
    {
        try {
            $this->llamar('answerCallbackQuery', array_filter(['callback_query_id' => $callbackId, 'text' => $texto], fn ($v) => $v !== null));
        } catch (RuntimeException) {
            // Vencido (más de 15 s): no afecta el flujo.
        }
    }

    public function escribiendo(int $chatId): void
    {
        try {
            $this->llamar('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        } catch (RuntimeException) {
        }
    }

    /** Descarga un archivo por file_id. @return array{bytes: string, ruta: string} */
    public function descargar(string $fileId, int $maxBytes): array
    {
        $f = $this->llamar('getFile', ['file_id' => $fileId]);
        if (($f['file_size'] ?? 0) > $maxBytes || empty($f['file_path'])) {
            throw new RuntimeException('Archivo demasiado grande o no disponible.');
        }
        try {
            $res = Http::timeout(60)->get($this->base.'/file/bot'.$this->token.'/'.$f['file_path']);
        } catch (Throwable $e) {
            throw new RuntimeException($this->limpiar('Telegram getFile: '.$e->getMessage()));
        }
        if ($res->failed() || strlen($res->body()) > $maxBytes) {
            throw new RuntimeException('Telegram: no se pudo descargar el archivo ('.$res->status().').');
        }

        return ['bytes' => $res->body(), 'ruta' => (string) $f['file_path']];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function llamar(string $metodo, array $params): array
    {
        if (! $this->configurado()) {
            throw new RuntimeException('Telegram no está configurado.');
        }
        try {
            /** @var Response $res */
            $res = Http::acceptJson()->connectTimeout(10)->timeout(30)
                ->retry(2, 500, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
                ->post($this->base.'/bot'.$this->token.'/'.$metodo, $params);
        } catch (Throwable $e) {
            throw new RuntimeException($this->limpiar("Telegram {$metodo}: ".$e->getMessage()));
        }
        if (! $res->successful() || ! $res->json('ok')) {
            throw new RuntimeException($this->limpiar("Telegram {$metodo} {$res->status()}: ".mb_substr((string) $res->json('description', ''), 0, 200)));
        }

        return (array) ($res->json('result') ?? []);
    }

    private function limpiar(string $mensaje): string
    {
        return $this->configurado() ? str_replace((string) $this->token, '***', $mensaje) : $mensaje;
    }

    /** Telegram corta en 4096 caracteres. */
    private static function recortar(string $texto): string
    {
        return mb_strlen($texto) > 4000 ? mb_substr($texto, 0, 3990).'…' : $texto;
    }

    /**
     * Teclado inline: filas de [texto, callback_data].
     *
     * @param  list<list<array{0: string, 1: string}>>  $filas
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function botones(array $filas): array
    {
        return ['inline_keyboard' => array_map(fn ($fila) => array_map(fn ($b) => ['text' => $b[0], 'callback_data' => $b[1]], $fila), $filas)];
    }
}
