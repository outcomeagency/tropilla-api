<?php

namespace App\Domain\Documentos\Borradores\Extraccion;

/**
 * Proveedor de IA para leer una planilla fotografiada (TPA-61). Recibe las páginas originales y el JSON Schema de la
 * familia; devuelve la salida estructurada tal cual la produjo el modelo (la normalización y las advertencias son del
 * dominio, no del proveedor). Implementaciones: `GeminiExtractor` (API de Gemini, TPA-62), `ClaudeExtractor` (API de
 * Anthropic), `FakeExtractor` (tests),
 * `ExtractorDeshabilitado` (sin credenciales: el borrador queda `fallido` y se transcribe a mano).
 */
interface Extractor
{
    /**
     * @param  list<array{mime: string, bytes: string}>  $paginas  originales en orden
     * @param  array<string, mixed>  $esquema  JSON Schema de la salida
     *
     * @throws ExtraccionFallida
     */
    public function extraer(array $paginas, string $instrucciones, array $esquema): ResultadoExtraccion;
}
