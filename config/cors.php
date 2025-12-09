<?php

return [
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'me',
        'broadcasting/auth',
        'auth/*'
    ],

    'allowed_methods' => ['*'],

<<<<<<< Updated upstream
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],
=======
    'allowed_origins' => env('APP_ENV') === 'local' 
        ? ['*'] 
        : explode(',', env('CORS_ALLOWED_ORIGINS', '')),

    'allowed_origins_patterns' => [],
>>>>>>> Stashed changes

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
