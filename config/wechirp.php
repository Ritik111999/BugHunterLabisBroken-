<?php

/**
 * WeChirp product stack — single source of truth for providers and defaults.
 * STT is Deepgram-only for live + batch; OpenAI powers post-meeting intelligence.
 */
return [

    'stack_version' => '2026-05',

    /*
    |--------------------------------------------------------------------------
    | Speech-to-text (locked: Deepgram)
    |--------------------------------------------------------------------------
    */
    'stt' => [
        'provider' => 'deepgram',
        'live_model' => env('DEEPGRAM_LIVE_MODEL', 'nova-3'),
        'prerecord_model' => env('MEETING_DEEPGRAM_PRERECORD_MODEL', 'nova-2'),
        'language' => env('MEETING_DEEPGRAM_LANGUAGE', 'en'),
        'endpointing_ms' => (int) env('DEEPGRAM_LIVE_ENDPOINTING_MS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-meeting AI (OpenAI — structured summaries + embeddings search)
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'provider' => 'openai',
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'max_transcript_chars' => (int) env('WECHIRP_AI_MAX_TRANSCRIPT_CHARS', 14000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Speaker identity (ECAPA voiceprints via Python / SpeechBrain)
    |--------------------------------------------------------------------------
    */
    'voiceprint' => [
        'engine' => 'speechbrain',
        'setup_command' => 'bash scripts/setup-voiceprint.sh',
        'verify_command' => 'php artisan voiceprint:verify',
    ],

    /*
    |--------------------------------------------------------------------------
    | Live audio relay (browser → Deepgram; Amp PHP WebSocket server)
    |--------------------------------------------------------------------------
    */
    'relay' => [
        // php-amphp | node — node: relay-gateway/server.mjs + /api/internal/relay/*
        'engine' => env('WECHIRP_RELAY_ENGINE', 'php-amphp'),
        'artisan_command' => 'deepgram:relay',
        'node_command' => 'node relay-gateway/server.mjs',
        'cluster_proxy' => 'relay-gateway/cluster-proxy.mjs',
        'cluster_script' => 'scripts/run-relay-cluster.sh',
        'auth_mode' => 'post',
    ],

    /*
    |--------------------------------------------------------------------------
    | Client surfaces
    |--------------------------------------------------------------------------
    */
    'client' => [
        'web' => 'react-vite',
        'mobile' => 'capacitor',
        'realtime_ui' => 'sse-and-websocket',
    ],

    /*
    |--------------------------------------------------------------------------
    | Meeting chunk storage (intro + HTTP chunks)
    |--------------------------------------------------------------------------
    |
    | meeting_audio = local disk under storage/app/meeting_audio
    | s3            = AWS S3 / R2 (WECHIRP_AUDIO_PREFIX as key prefix on bucket)
    |
    */
    'storage' => [
        'audio_disk' => env('WECHIRP_AUDIO_DISK', 'meeting_audio'),
        'audio_prefix' => env('WECHIRP_AUDIO_PREFIX', 'meeting_chunks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues (production: redis + audio,default workers)
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'audio' => env('WECHIRP_QUEUE_AUDIO', 'audio'),
        'default' => env('WECHIRP_QUEUE_DEFAULT', 'default'),
        'worker_command' => 'php artisan queue:work redis --queue=audio,default --tries=3 --timeout=0',
    ],

    /*
    |--------------------------------------------------------------------------
    | Production recommendations (documented defaults; not enforced in code)
    |--------------------------------------------------------------------------
    */
    'production' => [
        'database' => 'pgsql',
        'queue' => 'redis',
        'cache' => 'redis',
        'filesystem' => 's3',
        'relay_tls' => 'wss behind reverse proxy',
        'env_template' => '.env.production.example',
    ],

];
