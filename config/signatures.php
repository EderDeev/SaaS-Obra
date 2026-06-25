<?php

return [
    'driver' => env('SIGNATURE_DRIVER', 'local'),

    'opensign' => [
        'base_url' => env('OPENSIGN_BASE_URL'),
        'api_key' => env('OPENSIGN_API_KEY'),
        'create_request_path' => env('OPENSIGN_CREATE_REQUEST_PATH', '/createdocument'),
        'webhook_secret' => env('OPENSIGN_WEBHOOK_SECRET'),
        'verify_ssl' => env('OPENSIGN_VERIFY_SSL', true),
    ],
];
