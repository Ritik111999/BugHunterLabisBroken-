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
        'python' => (function (): string {
            $python = (string) env('MEETING_ANALYZER_PYTHON', '');
            $python = trim($python);
            if ($python !== '') {
                return $python;
            }

            $venvPython = base_path('scripts/.venv/bin/python');
            return file_exists($venvPython) ? $venvPython : 'python3';
        })(),
        'script' => (function (): string {
            $script = (string) env('MEETING_ANALYZER_SCRIPT', '');
            $script = trim($script);
            if ($script !== '') {
                return $script;
            }

            return base_path('scripts/analyze_audio.py');
        })(),
        'timeout_seconds' => (int) env('MEETING_ANALYZER_TIMEOUT', 60),
    ],

    // Post-meeting LLM summary (optional — SummarizeMeetingJob)
    'openai_api_key' => env('OPENAI_API_KEY', ''),
    'openai_model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'openai_embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
];

