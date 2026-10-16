<?php

/*
 * Configuración propia de TROPILLA.
 */
return [
    // URL pública de tropilla-next: base de los links de los mails (verificación, restablecer, invitaciones).
    'web_url' => rtrim((string) env('TROPILLA_WEB_URL', 'http://localhost:3000'), '/'),

    'auth' => [
        // Token web: vencimiento deslizante (api-v1.md §2).
        'web_token_dias' => (int) env('TROPILLA_WEB_TOKEN_DIAS', 30),
        'verificacion_email_horas' => 48,
        'restablecer_password_minutos' => 60,
    ],

    // Subida diferida de archivos (TPA-44). `driver`: s3 (presigna contra el bucket) | local (URLs firmadas a la propia API:
    // dev sin MinIO y tests). `endpoint_publico`: host con el que el teléfono llega al bucket (la firma SigV4 incluye el host,
    // así que se presigna con un cliente apuntado ahí; en el emulador Android suele ser http://10.0.2.2:9010).
    'archivos' => [
        'driver' => env('TROPILLA_ARCHIVOS_DRIVER', env('FILESYSTEM_DISK') === 's3' ? 's3' : 'local'),
        'endpoint_publico' => env('AWS_PUBLIC_ENDPOINT', env('AWS_ENDPOINT')),
        'parte_bytes' => (int) env('TROPILLA_PARTE_BYTES', 5 * 1024 * 1024),
        'multipart_desde_bytes' => (int) env('TROPILLA_MULTIPART_DESDE', 8 * 1024 * 1024),
        'max_bytes' => 100 * 1024 * 1024,
        'subida_horas' => 24,
    ],

    // Anclaje diario (TPA-40). `stub` confirma al instante (local/testing); en producción la app no arranca con stub.
    'anclaje' => [
        'driver' => env('TROPILLA_ANCLAJE_DRIVER', 'stub'),
        'calendarios' => array_values(array_filter(explode(',', (string) env('TROPILLA_OTS_CALENDARIOS',
            'https://a.pool.opentimestamps.org,https://b.pool.opentimestamps.org,https://a.pool.eternitywall.com')))),
        'explorador' => env('TROPILLA_BTC_EXPLORADOR', 'https://blockstream.info/api'),
    ],

    // Push (TPA-53): log (local/testing) | fcm (HTTP v1 con la cuenta de servicio de Firebase).
    'push' => [
        'driver' => env('TROPILLA_PUSH_DRIVER', 'log'),
        'fcm_credenciales' => env('TROPILLA_FCM_CREDENCIALES'),
    ],

    // Lectura asistida de planillas fotografiadas (TPA-61/62). `driver`: claude (API de Anthropic, key en ANTHROPIC_API_KEY) |
    // deshabilitado (sin key: el borrador queda `fallido` y se transcribe a mano) | fake (solo tests). Nunca se inventan datos.
    'extraccion' => [
        'driver' => env('TROPILLA_EXTRACCION_DRIVER', env('ANTHROPIC_API_KEY') ? 'claude' : 'deshabilitado'),
        'anthropic_key' => env('ANTHROPIC_API_KEY'),
        'modelo' => env('TROPILLA_EXTRACCION_MODELO', 'claude-haiku-4-5-20251001'),
        'max_tokens' => (int) env('TROPILLA_EXTRACCION_MAX_TOKENS', 8192),
    ],

    // Pepper del HMAC de CUIT (búsqueda exacta / unicidad sin descifrar). modelo-datos §2.3.
    'cuit_pepper' => env('TROPILLA_CUIT_PEPPER', env('APP_KEY')),
];
