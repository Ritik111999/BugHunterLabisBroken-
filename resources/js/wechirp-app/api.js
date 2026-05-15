const LS_TOKEN = 'wechirp_app_token';
const LS_USER = 'wechirp_app_user';

const DEFAULT_TIMEOUT_MS = 28_000;
const RETRYABLE_STATUS = new Set([408, 425, 429, 500, 502, 503, 504]);

export function getToken() {
    return localStorage.getItem(LS_TOKEN) || '';
}

export function setAuth(token, user) {
    if (token) {
        localStorage.setItem(LS_TOKEN, token);
        localStorage.setItem(LS_USER, JSON.stringify(user ?? {}));
    } else {
        clearAuth();
    }
}

export function clearAuth() {
    localStorage.removeItem(LS_TOKEN);
    localStorage.removeItem(LS_USER);
}

export function getUser() {
    try {
        const raw = localStorage.getItem(LS_USER);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

/**
 * JSON API helper: same-origin `/api`, timeout, one soft retry on transient errors.
 *
 * @param {string} path e.g. `/meetings` (leading slash optional)
 * @param {RequestInit & { timeout?: number, retries?: number }} [opts]
 */
export async function api(path, { method = 'GET', headers = {}, body, timeout = DEFAULT_TIMEOUT_MS, retries = 1 } = {}) {
    const token = getToken();
    const h = {
        Accept: 'application/json',
        ...headers,
    };
    if (token) {
        h.Authorization = `Bearer ${token}`;
    }

    const url = `/api${path.startsWith('/') ? path : `/${path}`}`;
    let lastErr = null;

    for (let attempt = 0; attempt <= retries; attempt++) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeout);
        try {
            const res = await fetch(url, { method, headers: h, body, signal: controller.signal });
            clearTimeout(timer);
            const text = await res.text();
            let json = null;
            try {
                json = JSON.parse(text);
            } catch {
                /* plain text */
            }
            if (!res.ok) {
                if (attempt < retries && RETRYABLE_STATUS.has(res.status)) {
                    await sleep(320 * (attempt + 1));
                    continue;
                }
                const msg =
                    (json && (json.message || json.error)) ||
                    (typeof json === 'string' ? json : null) ||
                    text ||
                    `HTTP ${res.status}`;
                const err = new Error(String(msg).slice(0, 400));
                err.status = res.status;
                err.payload = json && typeof json === 'object' ? json : null;
                if (json && typeof json === 'object' && json.code) {
                    err.code = json.code;
                }
                throw err;
            }
            return json ?? text;
        } catch (e) {
            clearTimeout(timer);
            lastErr = e;
            const aborted = e?.name === 'AbortError';
            const network = e instanceof TypeError;
            if (attempt < retries && (aborted || network)) {
                await sleep(400 * (attempt + 1));
                continue;
            }
            if (aborted) {
                const err = new Error('Request timed out. Check your connection and try again.');
                err.status = 408;
                throw err;
            }
            throw e;
        }
    }
    throw lastErr || new Error('Request failed');
}

function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
}

export function openMeetingStudio(meetingId, userName) {
    const token = getToken();
    if (!token) return;
    localStorage.setItem('wc.token', token);
    localStorage.setItem('wc.userName', userName || getUser()?.name || '');
    localStorage.setItem('wc.baseUrl', window.location.origin);
    const id = encodeURIComponent(String(meetingId));
    window.location.assign(`${window.location.origin}/app/meetings/${id}/studio`);
}
