<?php

namespace App\Support;

/**
 * Leyenda fija de los Resources públicos (api-v1 §5, functional/05 §3). Lenguaje de confianza: nunca
 * «certificado», «verificado oficialmente», «apto exportación» ni «garantizado por blockchain».
 */
final class Leyenda
{
    public const TEXTOS = [
        'es' => 'La información fue declarada por el productor. TROPILLA registra evidencia de integridad de los datos; no certifica su contenido ni reemplaza habilitaciones oficiales.',
        'en' => 'This information was declared by the producer. TROPILLA records evidence of data integrity; it does not certify its content or replace official authorizations.',
        'pt' => 'As informações foram declaradas pelo produtor. A TROPILLA registra evidências de integridade dos dados; não certifica seu conteúdo nem substitui habilitações oficiais.',
    ];

    public const VERIFICACION = [
        'es' => 'TROPILLA registra evidencia de integridad: prueba que este registro no cambió desde la fecha de anclaje. No certifica que su contenido sea verdadero.',
        'en' => 'TROPILLA records integrity evidence: it proves this record has not changed since the anchoring date. It does not certify that its content is true.',
        'pt' => 'A TROPILLA registra evidência de integridade: prova que este registro não mudou desde a data de ancoragem. Não certifica que seu conteúdo seja verdadeiro.',
    ];

    public static function locale(?string $pedido = null): string
    {
        $l = $pedido ?? app()->getLocale();

        return in_array($l, ['es', 'en', 'pt'], true) ? $l : 'es';
    }

    public static function texto(?string $locale = null): string
    {
        return self::TEXTOS[self::locale($locale)];
    }

    public static function verificacion(?string $locale = null): string
    {
        return self::VERIFICACION[self::locale($locale)];
    }
}
