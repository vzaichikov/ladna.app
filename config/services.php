<?php

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$appUrl = $appUrl !== '' ? $appUrl : 'http://localhost';
$appHost = parse_url($appUrl, PHP_URL_HOST);
$mediaMtxPublicUrl = is_string($appHost) && $appHost !== ''
    ? str_replace('://'.$appHost, '://'.(str_starts_with($appHost, 'cam.') ? $appHost : 'cam.'.$appHost), $appUrl)
    : $appUrl;

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

    'ollama_cloud' => [
        'base_url' => env('OLLAMA_CLOUD_BASE_URL', 'https://ollama.com'),
    ],

    'telegram' => [
        'typing_pulse_enabled' => env('TELEGRAM_TYPING_PULSE_ENABLED', true),
        'typing_refresh_seconds' => (float) env('TELEGRAM_TYPING_REFRESH_SECONDS', 4),
    ],

    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    ],

    'mediamtx' => [
        'api_url' => env('MEDIAMTX_API_URL', 'http://127.0.0.1:9997'),
        'public_url' => env('MEDIAMTX_PUBLIC_URL', $mediaMtxPublicUrl),
        'playback' => env('MEDIAMTX_PLAYBACK', 'webrtc'),
        'hls_prefix' => env('MEDIAMTX_HLS_PREFIX', '/hls'),
        'webrtc_prefix' => env('MEDIAMTX_WEBRTC_PREFIX', '/webrtc'),
        'source_on_demand' => env('MEDIAMTX_SOURCE_ON_DEMAND', true),
        'source_on_demand_start_timeout' => env('MEDIAMTX_SOURCE_ON_DEMAND_START_TIMEOUT', '20s'),
        'source_on_demand_close_after' => env('MEDIAMTX_SOURCE_ON_DEMAND_CLOSE_AFTER', '30s'),
        'rtsp_transport' => env('MEDIAMTX_RTSP_TRANSPORT', 'tcp'),
    ],

];
