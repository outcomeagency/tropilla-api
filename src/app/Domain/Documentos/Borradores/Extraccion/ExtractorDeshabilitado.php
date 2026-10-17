<?php

namespace App\Domain\Documentos\Borradores\Extraccion;

/** Sin proveedor configurado (ni GEMINI_API_KEY ni ANTHROPIC_API_KEY): nunca se inventan datos. */
final class ExtractorDeshabilitado implements Extractor
{
    public function extraer(array $paginas, string $instrucciones, array $esquema, ?string $instruccionSistema = null): ResultadoExtraccion
    {
        throw new ExtraccionFallida(__('La lectura automática no está disponible en este servidor. Podés transcribir el documento a mano.'));
    }
}
