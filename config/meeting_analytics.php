<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meeting analytics (audio processing)
    |--------------------------------------------------------------------------
    |
    | This config controls how chunk/full-audio analysis runs.
    | We keep real-time stats in Redis/Cache only (no DB reads).
    |
    */

    // Ignore the first N seconds (intro / calibration / greeting).
    'intro_seconds' => (int) env('MEETING_INTRO_SECONDS', 0),

    // Python analyzer entrypoint. Must output JSON to stdout.
    'analyzer' => [
        'python' => env('MEETING_ANALYZER_PYTHON', 'python3'),
        'script' => env('MEETING_ANALYZER_SCRIPT', base_path('scripts/analyze_audio.py')),
        'timeout_seconds' => (int) env('MEETING_ANALYZER_TIMEOUT', 60),
    ],
];

