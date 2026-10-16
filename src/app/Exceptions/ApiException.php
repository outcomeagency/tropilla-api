<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de dominio con el formato único de api-v1.md §3:
 * {message, code, errors?, advertencias?, request_id}.
 *
 * Se lanza desde Actions/Policies; bootstrap/app.php lo renderiza.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  list<array{codigo: string, mensaje: string, detalle?: array<string, mixed>}>  $advertencias
     */
    public function __construct(
        public readonly int $status,
        public readonly string $codigo,
        string $message,
        public readonly array $errors = [],
        public readonly array $advertencias = [],
    ) {
        parent::__construct($message);
    }

    public static function noEncontrado(string $message = 'No encontrado.'): self
    {
        return new self(404, 'no_encontrado', $message);
    }

    public static function sinPermiso(string $message = 'No tenés permiso para esta acción.'): self
    {
        return new self(403, 'sin_permiso', $message);
    }

    /** @param array<string, list<string>> $errors */
    public static function validacion(string $message, array $errors = []): self
    {
        return new self(422, 'validacion', $message, $errors);
    }

    /** @param array<string, list<string>> $errors */
    public static function reglaBloqueante(string $message, array $errors = []): self
    {
        return new self(422, 'regla_bloqueante', $message, $errors);
    }

    /** @param array<string, mixed> $actual */
    public static function conflictoRowVersion(array $actual = []): self
    {
        return new self(409, 'conflicto_row_version', 'Otro usuario modificó este registro. Recargá y volvé a intentar.', ['_actual' => [json_encode($actual, JSON_UNESCAPED_UNICODE) ?: '']]);
    }
}
