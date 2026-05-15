/** Probe relay / STT readiness for live meetings. */
export async function fetchMeetingServicesHealth(api) {
    try {
        return await api('/health/meeting-services');
    } catch {
        return null;
    }
}

export async function probeRelayUp(relayWsUrl) {
    const base = String(relayWsUrl || '').trim().replace(/\/$/, '');
    if (!base) return false;
    const httpBase = base.replace(/^wss:/i, 'https:').replace(/^ws:/i, 'http:');
    try {
        const ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timer = ctrl ? setTimeout(() => ctrl.abort(), 2500) : null;
        const res = await fetch(`${httpBase}/up`, { method: 'GET', signal: ctrl?.signal });
        if (timer) clearTimeout(timer);
        return res.ok;
    } catch {
        return false;
    }
}

export function healthSummary(health) {
    if (!health) {
        return { ok: false, message: 'Could not reach server health check.' };
    }
    if (!health.stt_configured) {
        const key = health.stt_provider === 'pulse' ? 'PULSE_API_KEY' : 'DEEPGRAM_API_KEY';
        return { ok: false, message: `Speech-to-text is not configured. Set ${key} in .env on the server.` };
    }
    if (!health.relay_reachable) {
        return {
            ok: false,
            message: `Live WebSocket relay is offline. Run: php artisan deepgram:relay (expected ${health.relay_ws_url || 'ws://127.0.0.1:9001'}). HTTP chunks still work.`,
            httpOk: true,
        };
    }
    const voiceReady =
        health.voiceprint_verified === true ||
        health.voiceprint_venv_ready === true ||
        (typeof window !== 'undefined' && window.__WECHIRP_BOOT__?.voiceprintReady === true);
    if (!voiceReady) {
        return {
            ok: false,
            message:
                'Voice fingerprint not installed — run on your Mac: npm run setup:voiceprint (then restart the server). Solo live STT still works.',
            httpOk: true,
        };
    }
    return { ok: true, message: 'Live speech-to-text + voice fingerprint ready.' };
}
