/* eslint-disable @typescript-eslint/no-require-imports */
const { existsSync, readFileSync } = require('node:fs');
const { resolve } = require('node:path');

/** Minimal .env parser (APP_URL, CAPACITOR_SERVER_URL) — no shell expansion. */
function parseEnvFile(filePath) {
    if (!existsSync(filePath)) {
        return {};
    }
    const out = {};
    for (const rawLine of readFileSync(filePath, 'utf8').split('\n')) {
        const line = rawLine.trim();
        if (!line || line.startsWith('#')) {
            continue;
        }
        const eq = line.indexOf('=');
        if (eq === -1) {
            continue;
        }
        const key = line.slice(0, eq).trim();
        let val = line.slice(eq + 1).trim();
        if (
            (val.startsWith('"') && val.endsWith('"')) ||
            (val.startsWith("'") && val.endsWith("'"))
        ) {
            val = val.slice(1, -1);
        }
        out[key] = val;
    }
    return out;
}

const rootEnvPath = resolve(__dirname, '..', '.env');
const env = parseEnvFile(rootEnvPath);

/** iOS Simulator + Capacitor: prefer 127.0.0.1 over localhost so the host matches the WebView origin. */
function normalizeLocalhostForSimulator(url) {
    if (!url || typeof url !== 'string') {
        return url;
    }
    try {
        const u = new URL(url);
        const h = u.hostname.toLowerCase();
        if (h === 'localhost' || h === '::1') {
            u.hostname = '127.0.0.1';
        }
        return u.toString().replace(/\/$/, '');
    } catch {
        return url.replace(/\/$/, '');
    }
}

const appUrl = normalizeLocalhostForSimulator(env.APP_URL || 'http://127.0.0.1:9000');
const serverUrl = normalizeLocalhostForSimulator(env.CAPACITOR_SERVER_URL || `${appUrl}/app`);
const nativeUaToken = env.WECHIRP_NATIVE_UA_TOKEN || 'WeChirpCapacitorShell';

/** @type {import('@capacitor/cli').CapacitorConfig} */
module.exports = {
    appId: 'com.wechirp.app',
    appName: 'WeChirp',
    webDir: 'www',
    appendUserAgent: nativeUaToken,
    server: {
        url: serverUrl,
        cleartext: true,
    },
};
