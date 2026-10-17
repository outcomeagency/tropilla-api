<?php

namespace App\Domain\Ia;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente REST de Gemini (`v1beta/models/{modelo}:generateContent`, header `x-goog-api-key`). Genérico: el asistente del
 * bot de Telegram (TPA-77) lo usa con function calling y para transcribir audios. La key sale del entorno; nunca se
 * guarda ni aparece en errores ni logs. Errores transitorios (red, 429, 5xx) se reintentan con espera creciente.
 */
class GeminiCliente
{
    public const URL_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly ?string $apiKey,
        public readonly string $modelo = 'gemini-flash-latest',
        private readonly int $timeout = 60,
        private readonly int $reintentos = 2,
    ) {}

    public static function desdeConfig(): self
    {
        return new self(config('tropilla.asistente.gemini_key'), (string) config('tropilla.asistente.modelo'), (int) config('tropilla.asistente.timeout_segundos'));
    }

    public function disponible(): bool
    {
        return is_string($this->apiKey) && $this->apiKey !== '';
    }

    /**
     * @param  array<string, mixed>  $cuerpo  GenerateContentRequest
     * @return array<string, mixed> GenerateContentResponse
     *
     * @throws GeminiError
     */
    public function generar(array $cuerpo): array
    {
        if (! $this->disponible()) {
            throw new GeminiError('El asistente no está configurado en este servidor.');
        }
        $transitorio = fn (Response $res) => $res->status() === 429 || $res->serverError();
        try {
            $res = Http::withHeaders(['x-goog-api-key' => (string) $this->apiKey])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($this->timeout)
                ->retry($this->reintentos + 1, fn (int $intento) => 1000 * $intento,
                    fn ($e) => $e instanceof ConnectionException || ($e->response !== null && $transitorio($e->response)), throw: false)
                ->post(self::URL_BASE.'/models/'.rawurlencode($this->modelo).':generateContent', $cuerpo);
        } catch (ConnectionException) {
            throw new GeminiError('El asistente no respondió.', true);
        }
        if ($transitorio($res)) {
            throw new GeminiError('El asistente no respondió.', true);
        }
        if ($res->failed()) {
            report(new GeminiError('Gemini generateContent '.$res->status().': '.mb_substr((string) $res->json('error.message', ''), 0, 300)));
            throw new GeminiError('El asistente rechazó el pedido.');
        }
        $r = (array) $res->json();
        if (($r['promptFeedback']['blockReason'] ?? null) !== null) {
            throw new GeminiError('El asistente no procesó este mensaje.');
        }

        return $r;
    }

    /** Contenido del primer candidato tal cual (para devolverlo al historial con sus `thoughtSignature`). @return array<string, mixed>|null */
    public static function contenido(array $respuesta): ?array
    {
        $c = $respuesta['candidates'][0]['content'] ?? null;

        return is_array($c) ? $c + ['role' => 'model'] : null;
    }

    /** Texto visible del primer candidato (sin las partes de razonamiento). */
    public static function texto(array $respuesta): string
    {
        $t = '';
        foreach ((array) ($respuesta['candidates'][0]['content']['parts'] ?? []) as $p) {
            if (! ($p['thought'] ?? false) && isset($p['text'])) {
                $t .= $p['text'];
            }
        }

        return trim($t);
    }

    /** @return list<array{name: string, args: array<string, mixed>}> */
    public static function llamadas(array $respuesta): array
    {
        $out = [];
        foreach ((array) ($respuesta['candidates'][0]['content']['parts'] ?? []) as $p) {
            if (isset($p['functionCall']['name'])) {
                $out[] = ['name' => (string) $p['functionCall']['name'], 'args' => (array) ($p['functionCall']['args'] ?? [])];
            }
        }

        return $out;
    }
}
