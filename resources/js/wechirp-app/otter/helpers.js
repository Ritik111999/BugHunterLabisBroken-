/** Otter-style consumer mode helpers. */

export function showDevTools() {
    const b = typeof window !== 'undefined' ? window.__WECHIRP_BOOT__ : null;
    if (b?.showDevTools === true) return true;
    if (isConsumerMode()) return false;
    return b?.appEnv === 'local';
}

/** Voice tuning + live log panels in Studio (dev tools or explicit boot flag). */
export function showStudioDebugPanels() {
    const b = typeof window !== 'undefined' ? window.__WECHIRP_BOOT__ : null;
    if (b?.studioDebugPanels === true) return true;
    return showDevTools();
}

export function isConsumerMode() {
    const b = typeof window !== 'undefined' ? window.__WECHIRP_BOOT__ : null;
    if (b?.consumerMode === false) return false;
    return true;
}

export function isCapacitorNative() {
    const c = typeof window !== 'undefined' ? window.Capacitor : null;
    return !!(c?.isNativePlatform?.());
}

export function isCapacitorIos() {
    const c = typeof window !== 'undefined' ? window.Capacitor : null;
    return isCapacitorNative() && c?.getPlatform?.() === 'ios';
}

/** Best-effort; Capacitor iOS WebView often omits the word "Simulator" in UA. */
export function isLikelyIosSimulator() {
    if (typeof navigator === 'undefined') return false;
    const ua = navigator.userAgent || '';
    if (/\bSimulator\b/i.test(ua)) return true;
    if (isCapacitorIos() && /Mac OS X|Macintosh/i.test(ua)) return true;
    if (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1) return true;
    return false;
}

export function formatMeetingStatus(status, meeting) {
    if (meeting?.ended_at || status === 'completed' || status === 'ended') return 'Completed';
    if (status === 'processing' || (meeting?.started_at && !meeting?.ended_at)) return 'Recording';
    return 'Ready';
}

export function formatMeetingDate(iso) {
    if (!iso) return '';
    try {
        const d = new Date(iso);
        return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    } catch {
        return '';
    }
}

/** Parse "[Name] text" or "Name: text" from stored transcript lines. */
export function parseTranscriptLine(text) {
    const raw = String(text || '').trim();
    if (!raw) return { speaker: 'Speaker', text: '' };
    const m = raw.match(/^\[([^\]]+)\]\s*(.*)$/s) || raw.match(/^([^:]{1,40}):\s*(.+)$/s);
    if (m) {
        return { speaker: m[1].trim() || 'Speaker', text: (m[2] || '').trim() };
    }
    return { speaker: 'Speaker', text: raw };
}

export function sentimentLabel(sentiment) {
    if (!sentiment || typeof sentiment !== 'object') return null;
    const s = String(sentiment.label || sentiment.score || '').toLowerCase();
    if (s.includes('pos')) return 'Positive';
    if (s.includes('neg')) return 'Negative';
    return 'Neutral';
}
