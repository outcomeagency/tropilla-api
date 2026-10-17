<?php

namespace App\Domain\Documentos\Borradores\Extraccion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Extracción con la API de Gemini (REST `v1beta/models/{modelo}:generateContent`, TPA-62): visión sobre las páginas
 * originales (inline base64) + salida JSON estructurada (`responseSchema`, el JSON Schema de la familia traducido al
 * subconjunto OpenAPI de Gemini). Si el esquema usa algo que Gemini no soporta, va como instrucción y la respuesta se
 * valida igual. Modelo por defecto `gemini-flash-latest` (configurable). Razonamiento `low` por defecto: transcribir no
 * necesita más y baja la latencia de ~80 s a ~8 s (vacío lo omite, para modelos sin `thinkingLevel`). Timeout 120 s y un
 * reintento interno ante red/429/5xx: el peor caso (~241 s) entra en el timeout del job (300 s); después reintenta la
 * Action. La key sale del entorno; nunca se guarda.
 */
final class GeminiExtractor implements Extractor
{
    public const URL_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'];

    private const SISTEMA = 'Transcribís planillas ganaderas argentinas fotografiadas. Copiás solo lo que se ve escrito; si un dato no '
        .'está o no se lee, va null con confianza baja. Nunca completás con valores probables ni corregís números.';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelo = 'gemini-flash-latest',
        private readonly int $maxTokens = 32768,
        private readonly int $timeout = 120,
        private readonly int $reintentos = 1,
        private readonly ?string $razonamiento = 'low',
    ) {}

    public function extraer(array $paginas, string $instrucciones, array $esquema, ?string $instruccionSistema = null): ResultadoExtraccion
    {
        $partes = [];
        foreach ($paginas as $i => $p) {
            if (! in_array($p['mime'], self::MIMES, true)) {
                throw new ExtraccionFallida(__('Formato de página no soportado: :mime.', ['mime' => $p['mime']]));
            }
            $partes[] = ['text' => 'Página '.($i + 1).':'];
            $partes[] = ['inlineData' => ['mimeType' => $p['mime'], 'data' => base64_encode($p['bytes'])]];
        }

        $generacion = ['responseMimeType' => 'application/json', 'temperature' => 0, 'maxOutputTokens' => $this->maxTokens];
        if ($this->razonamiento !== null && $this->razonamiento !== '') {
            $generacion['thinkingConfig'] = ['thinkingLevel' => $this->razonamiento];
        }
        try {
            $generacion['responseSchema'] = self::esquemaGemini($esquema);
        } catch (InvalidArgumentException) {
            $instrucciones .= "\n\nRespondé únicamente con un objeto JSON que valide este JSON Schema:\n"
                .json_encode($esquema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $partes[] = ['text' => $instrucciones];

        $r = $this->pedir([
            'systemInstruction' => ['parts' => [['text' => $instruccionSistema ?? self::SISTEMA]]],
            'contents' => [['role' => 'user', 'parts' => $partes]],
            'generationConfig' => $generacion,
        ]);

        if (($r['promptFeedback']['blockReason'] ?? null) !== null) {
            throw new ExtraccionFallida(__('El servicio de lectura no procesó este documento.'));
        }
        $candidato = $r['candidates'][0] ?? null;
        $fin = $candidato['finishReason'] ?? null;
        if ($fin === 'MAX_TOKENS') {
            throw new ExtraccionFallida(__('El documento es demasiado largo para leerlo de una vez.'));
        }
        if ($candidato === null || ! in_array($fin, [null, 'STOP'], true)) {
            throw new ExtraccionFallida(__('El servicio de lectura no procesó este documento.'));
        }
        $texto = '';
        foreach ((array) ($candidato['content']['parts'] ?? []) as $parte) {
            if (! ($parte['thought'] ?? false) && isset($parte['text'])) {
                $texto .= $parte['text'];
            }
        }
        $salida = json_decode($texto, true);
        if (! is_array($salida) || array_is_list($salida)) {
            throw new ExtraccionFallida(__('La lectura no devolvió datos válidos.'), true);
        }

        $uso = $r['usageMetadata'] ?? [];

        return new ResultadoExtraccion($salida, 'google', (string) ($r['modelVersion'] ?? $this->modelo),
            isset($uso['promptTokenCount']) ? (int) $uso['promptTokenCount'] : null,
            isset($uso['candidatesTokenCount']) ? (int) $uso['candidatesTokenCount'] : null);
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    private function pedir(array $cuerpo): array
    {
        $transitorio = fn (Response $res) => $res->status() === 429 || $res->serverError();
        try {
            $res = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($this->timeout)
                ->retry($this->reintentos + 1, fn (int $intento) => 1000 * $intento,
                    fn (\Throwable $e) => $e instanceof ConnectionException || ($e instanceof RequestException && $transitorio($e->response)), throw: false)
                ->post(self::URL_BASE.'/models/'.rawurlencode($this->modelo).':generateContent', $cuerpo);
        } catch (ConnectionException) {
            throw new ExtraccionFallida(__('El servicio de lectura no respondió: se reintenta.'), true);
        }

        if ($transitorio($res)) {
            throw new ExtraccionFallida(__('El servicio de lectura no respondió: se reintenta.'), true);
        }
        if ($res->failed()) {
            report(new \RuntimeException('Gemini generateContent '.$res->status().': '.mb_substr((string) $res->json('error.message', ''), 0, 300)));
            throw new ExtraccionFallida(__('El servicio de lectura rechazó el pedido (:s).', ['s' => $res->status()]));
        }

        return (array) $res->json();
    }

    /**
     * JSON Schema → `Schema` de Gemini (subconjunto OpenAPI 3.0): `type: [X, "null"]` → `type: X, nullable: true`;
     * se descartan las claves sin efecto (additionalProperties, $schema, title) y se fija `propertyOrdering`.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException si usa algo que Gemini no representa (anyOf, $ref, unión de tipos…)
     */
    public static function esquemaGemini(array $s): array
    {
        $out = [];
        $tipos = (array) ($s['type'] ?? throw new InvalidArgumentException('Esquema sin type.'));
        $noNulos = array_values(array_diff($tipos, ['null']));
        if (count($noNulos) !== 1 || ! in_array($noNulos[0], ['object', 'array', 'string', 'number', 'integer', 'boolean'], true)) {
            throw new InvalidArgumentException('Tipo no representable: '.json_encode($s['type']));
        }
        $out['type'] = strtoupper($noNulos[0]);
        if (count($tipos) > 1 || ($s['nullable'] ?? false)) {
            $out['nullable'] = true;
        }
        foreach ($s as $clave => $valor) {
            match ($clave) {
                'type', 'nullable', 'additionalProperties', '$schema', 'title' => null,
                'properties' => $out['properties'] = array_map(fn ($p) => self::esquemaGemini((array) $p), (array) $valor),
                'items' => $out['items'] = self::esquemaGemini((array) $valor),
                'enum' => $out['enum'] = array_map('strval', (array) $valor),
                'required', 'description', 'format', 'minItems', 'maxItems', 'minimum', 'maximum' => $out[$clave] = $valor,
                default => throw new InvalidArgumentException("Clave de esquema no soportada: {$clave}"),
            };
        }
        if (isset($out['properties'])) {
            $out['propertyOrdering'] = array_keys($out['properties']);
        }

        return $out;
    }
}
