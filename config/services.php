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

    'deepgram' => [
        'api_key' => env('DEEPGRAM_API_KEY'),
        'live_model' => env('DEEPGRAM_LIVE_MODEL', 'nova-3'),
    ],

    /*
    | Smallest AI Waves — Pulse STT (WebSocket + HTTP get_text).
    | MEETING_STT_PROVIDER=pulse uses PULSE_API_KEY for live relay + batch analyzer.
    */
    'pulse' => [
        'api_key' => env('PULSE_API_KEY'),
        'language' => env('PULSE_LANGUAGE', 'en'),
    ],

    'python' => [
        'bin' => env('PYTHON_BIN'),
    ],

];
