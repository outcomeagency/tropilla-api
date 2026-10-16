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

    // Pepper del HMAC de CUIT (búsqueda exacta / unicidad sin descifrar). modelo-datos §2.3.
    'cuit_pepper' => env('TROPILLA_CUIT_PEPPER', env('APP_KEY')),
];
