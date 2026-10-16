<?php

namespace App\Rules;

/** Patrones compartidos del contrato (api-v1 §5, sync §4.5). */
final class Reglas
{
    public const DECIMAL = 'regex:/^-?(0|[1-9][0-9]*)(\.[0-9]*[1-9])?$/';

    public const DECIMAL_POS = 'regex:/^(0|[1-9][0-9]*)(\.[0-9]*[1-9])?$/';

    public const HASH = 'regex:/^[0-9a-f]{64}$/';

    public const UUID = 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public const CODIGO = 'regex:/^[A-Za-z0-9_.-]{1,64}$/';
}
