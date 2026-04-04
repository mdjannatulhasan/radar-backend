<?php

$frontendUrl = env('FRONTEND_URL');
$appUrl = env('APP_URL');

$allowedOrigins = array_values(array_filter([
    $frontendUrl,
    $appUrl,
]));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'OPTIONS'],
    'allowed_origins' => $allowedOrigins === [] ? ['http://localhost:3000'] : $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Origin', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
