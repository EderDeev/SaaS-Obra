<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'autodesk_aps' => [
        'client_id' => env('AUTODESK_APS_CLIENT_ID'),
        'client_secret' => env('AUTODESK_APS_CLIENT_SECRET'),
        'bucket_key' => env('AUTODESK_APS_BUCKET_KEY'),
        'region' => env('AUTODESK_APS_REGION', 'US'),
        'storage_limit_bytes' => (int) env('AUTODESK_APS_STORAGE_LIMIT_BYTES', 5368709120),
        'scopes' => array_values(array_filter(explode(' ', (string) env('AUTODESK_APS_SCOPES', 'data:read data:write data:create bucket:create bucket:read viewables:read')))),
        'verify_ssl' => env('AUTODESK_APS_VERIFY_SSL', true),
        'ca_bundle' => env('AUTODESK_APS_CA_BUNDLE'),
        'auto_process' => env('AUTODESK_APS_AUTO_PROCESS', true),
    ],

];
