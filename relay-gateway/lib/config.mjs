export function loadConfig() {
    const laravelUrl = (process.env.LARAVEL_URL || process.env.APP_URL || 'http://127.0.0.1:9000').replace(/\/$/, '');
    const secret = process.env.RELAY_INTERNAL_SECRET || '';
    const deepgramKey = process.env.DEEPGRAM_API_KEY || '';
    const host = process.env.WC_RELAY_GATEWAY_HOST || process.env.WC_RELAY_HOST || '127.0.0.1';
    const port = Number(process.env.WC_RELAY_GATEWAY_PORT || 9200);
    const model = process.env.DEEPGRAM_LIVE_MODEL || 'nova-3';
    const endpointing = Number(process.env.DEEPGRAM_LIVE_ENDPOINTING_MS || 100);
    const utteranceEnd = Number(process.env.DEEPGRAM_LIVE_UTTERANCE_END_MS || 900);
    const language = process.env.MEETING_DEEPGRAM_LANGUAGE || 'en';
    const maxConnections = Number(process.env.MEETING_RELAY_MAX_CONNECTIONS || 150);
    const persistMs = Number(process.env.MEETING_WS_PERSIST_INTERVAL_SECONDS || 8) * 1000;
    const statsMs = Number(process.env.MEETING_WS_STATS_INTERVAL_SECONDS || 0.1) * 1000;
    const transcriptMs = Number(process.env.MEETING_WS_TRANSCRIPT_PUSH_INTERVAL_SECONDS || 0.05) * 1000;
    const voiceChunkSec = Number(process.env.MEETING_NODE_VOICE_CHUNK_SECONDS || 2.5);

    return {
        laravelUrl,
        secret,
        deepgramKey,
        host,
        port,
        model,
        endpointing,
        utteranceEnd,
        language,
        maxConnections,
        persistMs: Math.max(2000, persistMs),
        statsMs: Math.max(50, statsMs),
        transcriptMs: Math.max(30, transcriptMs),
        voiceChunkSec: Math.max(1.5, voiceChunkSec),
    };
}
