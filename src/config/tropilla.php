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

    // Bot de Telegram del productor (TPA-77). Sin token o sin secreto el webhook responde 404 y no se envía nada.
    // El token y el secreto salen del entorno: nunca se guardan en la base ni se loguean.
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'tropilla_bot'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
        'codigo_minutos' => (int) env('TELEGRAM_CODIGO_MINUTOS', 10),
        'propuesta_horas' => 24,
        'max_archivo_mb' => 20,
        'consultas_por_hora' => (int) env('TELEGRAM_CONSULTAS_POR_HORA', 60),
    ],

    // Asistente en lenguaje natural del bot (TPA-77): Gemini REST `generateContent` con function calling sobre herramientas
    // de solo lectura (y la propuesta de cargas, que siempre se confirma). Sin key el bot responde que no está disponible.
    'asistente' => [
        'gemini_key' => env('GEMINI_API_KEY'),
        'modelo' => env('TROPILLA_ASISTENTE_MODELO', 'gemini-flash-latest'),
        'timeout_segundos' => (int) env('TROPILLA_ASISTENTE_TIMEOUT', 60),
        'max_pasos' => 5,
    ],

    // Alertas climáticas (TPA-70): fuentes gratuitas sin key, cache por punto y recálculo cada 6 h (scheduler `clima:actualizar`).
    'clima' => [
        'open_meteo_forecast_url' => env('TROPILLA_CLIMA_FORECAST_URL', 'https://api.open-meteo.com/v1/forecast'),
        'open_meteo_archive_url' => env('TROPILLA_CLIMA_ARCHIVE_URL', 'https://archive-api.open-meteo.com/v1/archive'),
        'noaa_oni_url' => env('TROPILLA_CLIMA_ONI_URL', 'https://www.cpc.ncep.noaa.gov/data/indices/oni.ascii.txt'),
        'timeout_segundos' => 10,
        'cache_pronostico_horas' => 3,
        'cache_historico_horas' => 24,
        'cache_enso_horas' => 24,
        'vigencia_horas' => 6,
    ],

    // Lectura asistida de planillas fotografiadas (TPA-61/62). `driver`: gemini (API de Gemini, key en GEMINI_API_KEY) |
    // claude (API de Anthropic, key en ANTHROPIC_API_KEY) | deshabilitado (el borrador queda `fallido` y se transcribe a
    // mano) | fake (solo tests). Por defecto: gemini si hay su key, si no claude si hay la suya, si no deshabilitado.
    'extraccion' => [
        'driver' => env('TROPILLA_EXTRACCION_DRIVER', env('GEMINI_API_KEY') ? 'gemini' : (env('ANTHROPIC_API_KEY') ? 'claude' : 'deshabilitado')),
        'anthropic_key' => env('ANTHROPIC_API_KEY'),
        'modelo' => env('TROPILLA_EXTRACCION_MODELO', 'claude-haiku-4-5-20251001'),
        'max_tokens' => (int) env('TROPILLA_EXTRACCION_MAX_TOKENS', 8192),
        'gemini_key' => env('GEMINI_API_KEY'),
        'gemini_modelo' => env('TROPILLA_EXTRACCION_GEMINI_MODELO', 'gemini-flash-latest'),
        'gemini_max_tokens' => (int) env('TROPILLA_EXTRACCION_GEMINI_MAX_TOKENS', 32768),
        'gemini_razonamiento' => env('TROPILLA_EXTRACCION_GEMINI_RAZONAMIENTO', 'low'), // thinkingLevel; vacío = no se envía
        'timeout_segundos' => (int) env('TROPILLA_EXTRACCION_TIMEOUT', 120),
    ],

    // Chequeo de contraparte por CUIT (TPA-72): consulta asistida, solicitada por el productor con finalidad y consentimiento.
    // BCRA Central de Deudores es una API pública sin credenciales. ARCA (constancia de inscripción) requiere certificado y
    // WSAA: el adaptador queda `deshabilitado` hasta tener credenciales y revisión legal (no se hace scraping ni se evade CAPTCHA).
    'contrapartes' => [
        'bcra_habilitado' => (bool) env('TROPILLA_CONTRAPARTES_BCRA', true),
        'bcra_url' => env('TROPILLA_CONTRAPARTES_BCRA_URL', 'https://api.bcra.gob.ar/CentralDeDeudores/v1.0'),
        'cheques_url' => env('TROPILLA_CONTRAPARTES_CHEQUES_URL', 'https://api.bcra.gob.ar/cheques/v1.0'),
        'arca_driver' => env('TROPILLA_CONTRAPARTES_ARCA_DRIVER', 'deshabilitado'),
        'timeout_segundos' => (int) env('TROPILLA_CONTRAPARTES_TIMEOUT', 15),
        'reintentos' => 2,
        'cache_horas' => 12,
        'max_consultas_dia' => (int) env('TROPILLA_CONTRAPARTES_MAX_DIA', 20),
        'retencion_dias' => (int) env('TROPILLA_CONTRAPARTES_RETENCION_DIAS', 180),
        // Estado de la revisión legal (investigacion/negocio-datos.md §2.2 y §5). Mientras sea `pendiente` no se automatizan
        // re-consultas, alertas ni lotes: solo la consulta puntual pedida por el productor.
        'revision_legal' => env('TROPILLA_CONTRAPARTES_REVISION_LEGAL', 'pendiente'),
    ],

    // Matching de camiones jaula (TPA-73): piloto concierge en un corredor (provincias INDEC separadas por coma). Fuera del
    // corredor el pedido se registra igual, marcado como lista de espera. `revision_legal`: rol de TROPILLA, seguro y responsabilidades.
    'fletes' => [
        'corredor' => env('TROPILLA_FLETES_CORREDOR', '18'),
        'radio_km' => (int) env('TROPILLA_FLETES_RADIO_KM', 150),
        'revision_legal' => env('TROPILLA_FLETES_REVISION_LEGAL', 'pendiente'),
    ],

    // Mercado de campos y pastajes (TPA-74): piloto regional NEA. Con `umbral_revision` reportes abiertos de personas distintas
    // el aviso pasa a «en revisión» hasta que el equipo lo resuelva.
    'campos' => [
        'provincias_piloto' => array_values(array_filter(explode(',', (string) env('TROPILLA_CAMPOS_PROVINCIAS', '18,22,34,54')))),
        'umbral_revision' => (int) env('TROPILLA_CAMPOS_UMBRAL_REVISION', 3),
        'max_consultas_dia' => (int) env('TROPILLA_CAMPOS_MAX_CONSULTAS_DIA', 10),
        'max_resultados' => 200,
    ],

    // Pepper del HMAC de CUIT (búsqueda exacta / unicidad sin descifrar). modelo-datos §2.3.
    'cuit_pepper' => env('TROPILLA_CUIT_PEPPER', env('APP_KEY')),
];
