<?php

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');
$appUrl = $appUrl !== '' ? $appUrl : 'http://localhost';
$appHost = parse_url($appUrl, PHP_URL_HOST);
$mediaMtxPublicUrl = is_string($appHost) && $appHost !== ''
    ? str_replace('://'.$appHost, '://'.(str_starts_with($appHost, 'cam.') ? $appHost : 'cam.'.$appHost), $appUrl)
    : $appUrl;
$festivalStreamPublicUrl = is_string($appHost) && $appHost !== ''
    ? str_replace('://'.$appHost, '://'.(str_starts_with($appHost, 'stream.') ? $appHost : 'stream.'.$appHost), $appUrl)
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
        'typing_refresh_seconds' => (float) env('TELEGRAM_TYPING_REFRESH_SECONDS', 2),
        'typing_max_seconds' => (int) env('TELEGRAM_TYPING_MAX_SECONDS', 120),
    ],

    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'transcription_model' => env('OPENAI_TRANSCRIPTION_MODEL', 'gpt-transcribe'),
    ],

    'voice_recognition' => [
        'ffmpeg_binary' => env('VOICE_FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('VOICE_FFPROBE_BINARY', 'ffprobe'),
    ],

    'mediamtx' => [
        'api_url' => env('MEDIAMTX_API_URL', 'http://127.0.0.1:9997'),
        'public_url' => env('MEDIAMTX_PUBLIC_URL', $mediaMtxPublicUrl),
        'rtsp_url' => env('MEDIAMTX_RTSP_URL', 'rtsp://127.0.0.1:8554'),
        'capture_url_template' => env('MEDIAMTX_CAPTURE_URL_TEMPLATE'),
        'playback' => env('MEDIAMTX_PLAYBACK', 'webrtc'),
        'hls_prefix' => env('MEDIAMTX_HLS_PREFIX', '/hls'),
        'webrtc_prefix' => env('MEDIAMTX_WEBRTC_PREFIX', '/webrtc'),
        'source_on_demand' => env('MEDIAMTX_SOURCE_ON_DEMAND', true),
        'source_on_demand_start_timeout' => env('MEDIAMTX_SOURCE_ON_DEMAND_START_TIMEOUT', '20s'),
        'source_on_demand_close_after' => env('MEDIAMTX_SOURCE_ON_DEMAND_CLOSE_AFTER', '30s'),
        'rtsp_transport' => env('MEDIAMTX_RTSP_TRANSPORT', 'tcp'),
    ],

    'festival_stream' => [
        'api_url' => env('FESTIVAL_STREAM_MEDIAMTX_API_URL', ''),
        'api_username' => env('FESTIVAL_STREAM_MEDIAMTX_API_USERNAME', ''),
        'api_password' => env('FESTIVAL_STREAM_MEDIAMTX_API_PASSWORD', ''),
        'public_url' => env('FESTIVAL_STREAM_PUBLIC_URL', $festivalStreamPublicUrl),
        'obs_server' => env('FESTIVAL_STREAM_OBS_SERVER', ''),
        'hls_origin_url' => env('FESTIVAL_STREAM_HLS_ORIGIN_URL', 'http://127.0.0.1:8898'),
        'internal_secret' => env('FESTIVAL_STREAM_INTERNAL_SECRET', ''),
        'ip_hmac_key' => env('FESTIVAL_STREAM_IP_HMAC_KEY', ''),
        'cookie_name' => env('FESTIVAL_STREAM_COOKIE_NAME', 'ladna_festival_stream'),
        'lease_seconds' => (int) env('FESTIVAL_STREAM_LEASE_SECONDS', 120),
        'bootstrap_seconds' => (int) env('FESTIVAL_STREAM_BOOTSTRAP_SECONDS', 30),
        'session_seconds' => (int) env('FESTIVAL_STREAM_SESSION_SECONDS', 28800),
        'staff_preview_session_seconds' => (int) env('FESTIVAL_STREAM_STAFF_PREVIEW_SESSION_SECONDS', 7200),
        'max_ip_leases' => (int) env('FESTIVAL_STREAM_MAX_IP_LEASES', 3),
        'connect_timeout' => (int) env('FESTIVAL_STREAM_CONNECT_TIMEOUT', 2),
        'timeout' => (int) env('FESTIVAL_STREAM_TIMEOUT', 5),
    ],

    'people_counter' => [
        'base_url' => env('PEOPLE_COUNTER_BASE_URL', 'http://127.0.0.1:8710'),
        'timeout' => env('PEOPLE_COUNTER_TIMEOUT', 30),
        'connect_timeout' => env('PEOPLE_COUNTER_CONNECT_TIMEOUT', 2),
        'capture_timeout' => env('PEOPLE_COUNTER_CAPTURE_TIMEOUT', 20),
        'capture_delay_seconds' => env('PEOPLE_COUNTER_CAPTURE_DELAY_SECONDS', 3),
        'ffmpeg_binary' => env('PEOPLE_COUNTER_FFMPEG_BINARY', 'ffmpeg'),
        'retention_days' => env('PEOPLE_COUNTER_RETENTION_DAYS', 14),
    ],

];
