<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Desde dónde se puede llamar a la API, separado por comas (CORS_ORIGENES). En el servidor:
    // la APK (https://localhost) y la página web, por ejemplo "https://localhost,https://app.su-dominio.co".
    // Sin la variable se permite cualquiera (desarrollo). La API usa token, no cookies.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ORIGENES', '*'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'], // para que la app lea el nombre del Excel (RF-26)

    'max_age' => 0,

    'supports_credentials' => false,

];
