/** Seed studio localStorage from Laravel when keys are unset (first visit / cleared storage). */
export function applyWechirpBoot() {
    if (typeof window === 'undefined') {
        return;
    }
    const b = window.__WECHIRP_BOOT__;
    if (!b || typeof b !== 'object') {
        return;
    }
    try {
        if (!localStorage.getItem('wc.relayWsUrl') && b.relayWsUrl) {
            localStorage.setItem('wc.relayWsUrl', String(b.relayWsUrl));
        }
        // One-time migration: old defaults pointed at 8081 / 8000.
        const r = localStorage.getItem('wc.relayWsUrl');
        if (r && (r.includes(':8081') || r.includes('//127.0.0.1:8081'))) {
            localStorage.setItem('wc.relayWsUrl', String(b.relayWsUrl || 'ws://127.0.0.1:9001'));
        }
        const base = localStorage.getItem('wc.baseUrl');
        if (base && (base.includes(':8000') || base.endsWith('http://127.0.0.1:8000'))) {
            try {
                const origin = window.location.origin;
                if (origin && origin.includes('127.0.0.1')) {
                    localStorage.setItem('wc.baseUrl', origin);
                }
            } catch {
                /* ignore */
            }
        }
    } catch {
        /* ignore */
    }
}
