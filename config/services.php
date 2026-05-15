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

    'relay_gateway' => [
        'internal_secret' => env('RELAY_INTERNAL_SECRET', ''),
        'laravel_url' => rtrim((string) env('APP_URL', 'http://127.0.0.1:9000'), '/'),
        'port' => (int) env('WC_RELAY_GATEWAY_PORT', 9200),
        'host' => env('WC_RELAY_GATEWAY_HOST', '127.0.0.1'),
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

    /*
    | Meeting demo + Capacitor: Deepgram relay (host/port + computed WS URL).
    | Start relay: php artisan deepgram:relay (uses relay_host / relay_port below).
    */
    'wechirp' => [
        'relay_host' => env('WC_RELAY_HOST', '127.0.0.1'),
        'relay_port' => (int) env('WC_RELAY_PORT', 9001),
        'relay_engine' => strtolower(trim((string) env('WECHIRP_RELAY_ENGINE', 'php-amphp'))),
        'relay_ws_url' => (function (): string {
            $explicit = trim((string) env('WC_RELAY_WS_URL', ''));
            if ($explicit !== '') {
                return $explicit;
            }
            $host = (string) env('WC_RELAY_HOST', '127.0.0.1');
            $engine = strtolower(trim((string) env('WECHIRP_RELAY_ENGINE', 'php-amphp')));
            $port = $engine === 'node'
                ? (int) env('WC_RELAY_GATEWAY_PORT', 9200)
                : (int) env('WC_RELAY_PORT', 9001);
            $appUrl = trim((string) env('APP_URL', ''));
            if (str_starts_with($appUrl, 'https://')) {
                $parsed = parse_url($appUrl);
                $wssHost = (string) ($parsed['host'] ?? $host);

                return sprintf('wss://%s:%d', $wssHost, $port);
            }

            return sprintf('ws://%s:%d', $host, $port);
        })(),
        /*
        | Appended to the Capacitor WebView user agent so Laravel can skip Vite "hot"
        | (127.0.0.1:9002 in dev) and serve built /public/build assets — required for iOS Simulator.
        */
        'native_shell_ua_token' => env('WECHIRP_NATIVE_UA_TOKEN', 'WeChirpCapacitorShell'),
    ],

];
