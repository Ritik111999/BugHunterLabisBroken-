<?php

return [

    'input_profile' => strtolower(trim((string) env('MEETING_AUDIO_INPUT_PROFILE', 'default'))),

    'intro_analyzer_timeout_seconds' => (int) env('MEETING_INTRO_ANALYZER_TIMEOUT', 0),

    'deepgram_prerecord_model' => trim((string) env('MEETING_DEEPGRAM_PRERECORD_MODEL', 'nova-2')) ?: 'nova-2',

    'deepgram_language' => trim((string) env('MEETING_DEEPGRAM_LANGUAGE', 'en')),

    'embed_max_seconds' => (float) env('MEETING_EMBED_MAX_SECONDS', 8),

    /*
    |--------------------------------------------------------------------------
    | Deepgram live streaming (relay)
    |--------------------------------------------------------------------------
    */
    'live' => [
        'endpointing_ms' => (int) env('DEEPGRAM_LIVE_ENDPOINTING_MS', 100),
        'utterance_end_ms' => (int) env('DEEPGRAM_LIVE_UTTERANCE_END_MS', 900),
        'vad_events' => filter_var(env('DEEPGRAM_LIVE_VAD_EVENTS', true), FILTER_VALIDATE_BOOL),
        'stats_interval_seconds' => (float) env('MEETING_WS_STATS_INTERVAL_SECONDS', 0.1),
        'stats_immediate_min_seconds' => (float) env('MEETING_WS_STATS_IMMEDIATE_MIN_SECONDS', 0),
        'transcript_push_interval_seconds' => (float) env('MEETING_WS_TRANSCRIPT_PUSH_INTERVAL_SECONDS', 0.03),
        'overlap_min_seconds' => (float) env('MEETING_WS_OVERLAP_MIN_SECONDS', 0.12),
        'slice_seconds' => (float) env('MEETING_WS_SLICE_SECONDS', 1),
        'embed_every_n' => (int) env('MEETING_WS_EMBED_EVERY_N', 5),
        'bootstrap_chunks' => (int) env('MEETING_WS_BOOTSTRAP_CHUNKS', 8),
        'label_window_seconds' => (float) env('MEETING_WS_LABEL_WINDOW_SECONDS', 8),
        'label_min_speech_seconds' => (float) env('MEETING_WS_LABEL_MIN_SPEECH_SECONDS', 2.5),
        'label_embed_cooldown_seconds' => (float) env('MEETING_WS_LABEL_EMBED_COOLDOWN_SECONDS', 4),
        'label_min_purity' => (float) env('MEETING_WS_LABEL_MIN_PURITY', 0.65),
        'voice_embed_interval_seconds' => (float) env('MEETING_WS_VOICE_EMBED_INTERVAL_SECONDS', 1.2),
        'fallback_window_seconds' => (float) env('MEETING_WS_FALLBACK_WINDOW_SECONDS', 1.0),
        'fast_identify' => filter_var(env('MEETING_WS_FAST_IDENTIFY', true), FILTER_VALIDATE_BOOL),
        'fast_identify_after_seconds' => (float) env('MEETING_WS_FAST_IDENTIFY_AFTER_SECONDS', 1.5),
        'fast_identify_min_score' => (float) env('MEETING_WS_FAST_IDENTIFY_MIN_SCORE', 0.68),
        'fast_identify_min_margin' => (float) env('MEETING_WS_FAST_IDENTIFY_MIN_MARGIN', 0.02),
        'fast_identify_window_seconds' => (float) env('MEETING_WS_FAST_IDENTIFY_WINDOW_SECONDS', 1.2),
        'max_audio_keep_seconds' => (float) env('MEETING_WS_MAX_AUDIO_KEEP_SECONDS', 35),
        'max_transcript_chars' => (int) env('MEETING_WS_MAX_TRANSCRIPT_CHARS', 6000),
        'pcm_vad_threshold' => (float) env('MEETING_PCM_VAD_THRESHOLD', 900.0),
        'pcm_vad_cap_with_enrollment' => (float) env('MEETING_PCM_VAD_CAP_WITH_ENROLLMENT', 300.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Voiceprint matching (live + batch)
    |--------------------------------------------------------------------------
    */
    'voiceprint' => [
        'threshold' => (float) env('MEETING_VOICEPRINT_THRESHOLD', 0.78),
        'margin' => (float) env('MEETING_VOICEPRINT_MARGIN', 0.06),
        'margin_2p' => (float) env('MEETING_VOICEPRINT_MARGIN_2P', 0.012),
        'early_boost' => (float) env('MEETING_VOICEPRINT_EARLY_BOOST', 0.05),
        'label_min_posterior' => (float) env('MEETING_VOICEPRINT_LABEL_MIN_POSTERIOR', 0.62),
        'label_min_posterior_gap' => (float) env('MEETING_VOICEPRINT_LABEL_MIN_POSTERIOR_GAP', 0.04),
        'label_stable_windows' => (int) env('MEETING_VOICEPRINT_LABEL_STABLE_WINDOWS', 2),
        'label_min_speech_before_bind' => (float) env('MEETING_VOICEPRINT_LABEL_MIN_SPEECH_BEFORE_BIND', 1.0),
        'fallback_threshold' => (float) env('MEETING_VOICEPRINT_FALLBACK_THRESHOLD', 0.0),
        'fallback_margin' => (float) env('MEETING_VOICEPRINT_FALLBACK_MARGIN', 0.02),
        'fallback_margin_2p' => (float) env('MEETING_VOICEPRINT_FALLBACK_MARGIN_2P', 0.008),
        'fallback_min_posterior' => (float) env('MEETING_VOICEPRINT_FALLBACK_MIN_POSTERIOR', 0.55),
        'fallback_min_posterior_gap' => (float) env('MEETING_VOICEPRINT_FALLBACK_MIN_POSTERIOR_GAP', 0.04),
        'fallback_stable_windows' => (int) env('MEETING_VOICEPRINT_FALLBACK_STABLE_WINDOWS', 2),
        'posterior_ema_alpha' => (float) env('MEETING_VOICEPRINT_POSTERIOR_EMA_ALPHA', 0.35),
        'softmax_temp' => (float) env('MEETING_VOICEPRINT_SOFTMAX_TEMP', 0.07),
        'expected_dim' => (int) env('MEETING_VOICEPRINT_EXPECTED_DIM', 192),
        'debug' => filter_var(env('MEETING_VOICEPRINT_DEBUG', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Intro enrollment (stricter defaults)
    |--------------------------------------------------------------------------
    */
    'intro' => [
        'duplicate_voiceprint_sim' => (float) env('MEETING_INTRO_DUPLICATE_VOICEPRINT_SIM', 0.972),
        'min_duration_seconds' => (float) env('MEETING_INTRO_MIN_DURATION_SECONDS', 1.2),
        'min_stt_words_for_enroll' => (int) env('MEETING_INTRO_MIN_STT_WORDS', 2),
        'max_voiceprints_per_person' => (int) env('MEETING_INTRO_MAX_VOICEPRINTS_PER_PERSON', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meeting start safety
    |--------------------------------------------------------------------------
    */
    'start' => [
        'duplicate_voiceprint_sim' => (float) env('MEETING_START_DUPLICATE_VOICEPRINT_SIM', 0.972),
        'relax_duplicate_check' => filter_var(env('MEETING_START_RELAX_DUPLICATE_CHECK', false), FILTER_VALIDATE_BOOL),
    ],

];
