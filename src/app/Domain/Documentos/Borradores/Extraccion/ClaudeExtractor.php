<?php

namespace App\Domain\Documentos\Borradores\Extraccion;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\Base64PDFSource;
use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\TextBlockParam;

/**
 * Extracción con la API de Claude (SDK oficial): visión sobre las páginas originales + salida estructurada
 * (`output_config.format` json_schema), así la respuesta siempre valida el esquema de la familia. Modelo económico por
 * defecto (`claude-haiku-4-5-20251001`, configurable). La key sale del entorno; nunca se guarda en la base.
 */
final class ClaudeExtractor implements Extractor
{
    private const MIMES_IMAGEN = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelo,
        private readonly int $maxTokens = 8192,
        private readonly ?Client $cliente = null,
    ) {}

    public function extraer(array $paginas, string $instrucciones, array $esquema): ResultadoExtraccion
    {
        $contenido = [];
        foreach ($paginas as $i => $p) {
            $contenido[] = TextBlockParam::with(text: 'Página '.($i + 1).':');
            $contenido[] = match (true) {
                in_array($p['mime'], self::MIMES_IMAGEN, true) => ImageBlockParam::with(source: Base64ImageSource::with(data: base64_encode($p['bytes']), mediaType: $p['mime'])),
                $p['mime'] === 'application/pdf' => DocumentBlockParam::with(source: Base64PDFSource::with(data: base64_encode($p['bytes']))),
                default => throw new ExtraccionFallida(__('Formato de página no soportado: :mime.', ['mime' => $p['mime']])),
            };
        }
        $contenido[] = TextBlockParam::with(text: $instrucciones);

        $cliente = $this->cliente ?? new Client(apiKey: $this->apiKey, requestOptions: ['timeout' => 120.0, 'maxRetries' => 2]);
        try {
            $m = $cliente->messages->create(
                maxTokens: $this->maxTokens,
                messages: [MessageParam::with(content: $contenido, role: 'user')],
                model: $this->modelo,
                system: 'Transcribís planillas ganaderas argentinas fotografiadas. Copiás solo lo que se ve escrito; si un dato no '
                    .'está o no se lee, va null con confianza baja. Nunca completás con valores probables ni corregís números.',
                outputConfig: OutputConfig::with(format: ['type' => 'json_schema', 'schema' => $esquema]),
            );
        } catch (RateLimitException|APIConnectionException $e) {
            throw new ExtraccionFallida(__('El servicio de lectura no respondió: se reintenta.'), true);
        } catch (APIStatusException $e) {
            report($e);
            throw new ExtraccionFallida(__('El servicio de lectura rechazó el pedido (:s).', ['s' => $e->status ?? 'error']), ($e->status ?? 0) >= 500);
        }

        if ($m->stopReason === 'refusal') {
            throw new ExtraccionFallida(__('El servicio de lectura no procesó este documento.'));
        }
        if ($m->stopReason === 'max_tokens') {
            throw new ExtraccionFallida(__('El documento es demasiado largo para leerlo de una vez.'));
        }
        $texto = '';
        foreach ($m->content as $bloque) {
            if ($bloque->type === 'text') {
                $texto .= $bloque->text;
            }
        }
        $salida = json_decode($texto, true);
        if (! is_array($salida)) {
            throw new ExtraccionFallida(__('La lectura no devolvió datos válidos.'), true);
        }

        return new ResultadoExtraccion($salida, 'anthropic', $this->modelo, $m->usage->inputTokens, $m->usage->outputTokens);
    }
}
