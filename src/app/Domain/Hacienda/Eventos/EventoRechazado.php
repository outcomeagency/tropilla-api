<?php

namespace App\Domain\Hacienda\Eventos;

use RuntimeException;

/**
 * Rechazo de un sobre de evento con los códigos de sync-e-integridad.md §7.3 (`huella_invalida`,
 * `canonicalizacion`, `esquema_invalido`, `tipo_desconocido`, `id_reutilizado`, `sin_permiso`,
 * `dependencia_faltante`, `regla_bloqueante`, `establecimiento_cerrado`). En el push va en `resultados[]`;
 * en la web se traduce a HTTP (ver `aHttp()`).
 */
class EventoRechazado extends RuntimeException
{
    private const REINTENTABLES = ['tipo_desconocido', 'dependencia_faltante'];

    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly string $codigo, string $mensaje, public readonly array $errors = [])
    {
        parent::__construct($mensaje);
    }

    public function reintentable(): bool
    {
        return in_array($this->codigo, self::REINTENTABLES, true);
    }

    /** @return array{0: int, 1: string} [status HTTP, code de api-v1 §3] */
    public function aHttp(): array
    {
        return match ($this->codigo) {
            'sin_permiso' => [403, 'sin_permiso'],
            'regla_bloqueante' => [422, 'regla_bloqueante'],
            'identificacion_duplicada' => [409, 'identificacion_duplicada'],
            'version_no_vigente' => [409, 'version_no_vigente'],
            'id_reutilizado' => [409, 'id_reutilizado'],
            default => [422, 'validacion'],
        };
    }
}
