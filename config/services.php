<?php

declare(strict_types=1);

return [
    'gateway' => [
        'url' => env('GATEWAY_URL', 'https://gateway.moonly.ai/v1'),
        'key' => env('GATEWAY_API_KEY'),
        'embedding_model' => env('GATEWAY_EMBEDDING_MODEL', 'qwen3-embedding-8b'),
    ],

    'gotenberg' => [
        'url' => env('GOTENBERG_URL'),
        'basic_auth_username' => env('GOTENBERG_BASIC_AUTH_USERNAME'),
        'basic_auth_password' => env('GOTENBERG_BASIC_AUTH_PASSWORD'),
    ],
];
