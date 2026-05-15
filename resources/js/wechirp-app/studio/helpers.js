export function isPlaceholder(name) {
    return /^Speaker\s+\d+$/i.test(name) || /^speaker_\d+$/i.test(name);
}

/** ECAPA voiceprint on participant — matches API MeetingController::start checks. */
export function hasEcapaVoiceprintEmbedding(p) {
    const ve = p?.voice_embedding;
    if (!ve || typeof ve !== 'object') return false;
    const countNumeric = (arr) => {
        if (!Array.isArray(arr)) return 0;
        let n = 0;
        for (const x of arr) {
            if (typeof x === 'number' && Number.isFinite(x)) n++;
            else if (typeof x === 'string' && x !== '' && Number.isFinite(Number(x))) n++;
        }
        return n;
    };
    if (countNumeric(ve.voiceprint) >= 32) return true;
    const list = ve.voiceprints;
    if (Array.isArray(list)) {
        for (const row of list) {
            if (countNumeric(row) >= 32) return true;
        }
    }
    return false;
}

export function formatElapsed(ms) {
    const s = Math.max(0, Math.floor(ms / 1000));
    const hh = String(Math.floor(s / 3600)).padStart(2, '0');
    const mm = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
    const ss = String(s % 60).padStart(2, '0');
    return `${hh}:${mm}:${ss}`;
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
