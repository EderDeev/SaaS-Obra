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
        'viewer_api' => env('AUTODESK_APS_VIEWER_API'),
        'storage_limit_bytes' => (int) env('AUTODESK_APS_STORAGE_LIMIT_BYTES', 5368709120),
        'scopes' => array_values(array_filter(explode(' ', (string) env('AUTODESK_APS_SCOPES', 'data:read data:write data:create bucket:create bucket:read viewables:read')))),
        'verify_ssl' => env('AUTODESK_APS_VERIFY_SSL', true),
        'ca_bundle' => env('AUTODESK_APS_CA_BUNDLE'),
        'auto_process' => env('AUTODESK_APS_AUTO_PROCESS', true),
        'queue' => env('AUTODESK_APS_QUEUE', 'aps'),
        'worker_timeout' => (int) env('AUTODESK_APS_WORKER_TIMEOUT', 900),
    ],

    'openai' => [
        'enabled' => env('AI_ASSISTANT_ENABLED', true),
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_CHAT_MODEL', 'gpt-5.6-luna'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 700),
        'history_messages' => (int) env('OPENAI_HISTORY_MESSAGES', 4),
        'history_character_limit' => (int) env('OPENAI_HISTORY_CHARACTER_LIMIT', 1200),
        'rag_max_sources' => (int) env('OPENAI_RAG_MAX_SOURCES', 8),
        'rag_source_character_limit' => (int) env('OPENAI_RAG_SOURCE_CHARACTER_LIMIT', 1600),
        'rag_context_character_limit' => (int) env('OPENAI_RAG_CONTEXT_CHARACTER_LIMIT', 12000),
        'tenant_monthly_token_limit' => (int) env('AI_TENANT_MONTHLY_TOKEN_LIMIT', 1000000),
        'user_monthly_token_limit' => (int) env('AI_USER_MONTHLY_TOKEN_LIMIT', 60000),
    ],

];
