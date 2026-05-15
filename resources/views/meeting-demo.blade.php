<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <title>WeChirp · Meetings</title>
    @include('partials.pwa-head', ['pwaAppleTitle' => 'WeChirp'])
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { brand: { DEFAULT: '#1AD0DE', dark: '#0ea5b5' } }
                }
            }
        };
    </script>
    <style>
        .pill { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.65rem; font-weight: 600; letter-spacing: 0.02em; }
        pre { white-space: pre-wrap; word-break: break-word; }
        details > summary { list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        .input-app {
            width: 100%; border-radius: 0.875rem; border: 1px solid rgb(226 232 240);
            background: rgb(248 250 252); padding: 0.7rem 1rem; font-size: 0.9375rem; outline: none;
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
        }
        .input-app:focus { border-color: #1AD0DE; background: #fff; box-shadow: 0 0 0 3px rgba(26, 208, 222, 0.22); }
        .input-app-sm { font-size: 0.8125rem; padding: 0.5rem 0.75rem; border-radius: 0.75rem; }
    </style>
</head>
<body class="min-h-[100dvh] min-h-screen bg-gradient-to-b from-slate-100 via-slate-50 to-slate-100 text-slate-900 antialiased pb-[max(1.5rem,env(safe-area-inset-bottom))]">

    <header class="sticky top-0 z-50 border-b border-slate-200/90 bg-white/90 backdrop-blur-lg shadow-sm shadow-slate-900/5 pt-[calc(0.65rem+env(safe-area-inset-top,0px))]">
        <div class="mx-auto flex max-w-lg items-start justify-between gap-3 px-4 pb-3.5">
            <div class="min-w-0">
                <h1 class="text-xl font-bold tracking-tight text-slate-900 sm:text-[1.35rem]"><span class="text-[#1AD0DE]">We</span>Chirp</h1>
                <p class="text-[0.7rem] font-medium uppercase tracking-wider text-slate-400">Meetings</p>
            </div>
            <div class="flex max-w-[58%] flex-wrap justify-end gap-1.5">
                <span id="badgeApi" class="pill bg-slate-100 text-slate-500">API · …</span>
                <span id="badgeWs" class="pill bg-slate-100 text-slate-500">WS · …</span>
                <span id="badgeDeepgram" class="pill bg-slate-100 text-slate-500">STT · …</span>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-lg space-y-4 px-4 py-4">

    <details class="overflow-hidden rounded-2xl border border-slate-200/90 bg-white shadow-md shadow-slate-900/[0.04]">
        <summary class="flex cursor-pointer select-none items-center justify-between gap-2 px-4 py-3.5 text-left active:bg-slate-50">
            <div>
                <div class="text-sm font-semibold text-slate-800">Server & relay</div>
                <div class="text-xs text-slate-500">Only change if you know the URL</div>
            </div>
            <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-[0.65rem] font-semibold text-slate-600">Show</span>
        </summary>
        <div class="space-y-4 border-t border-slate-100 px-4 py-4">
            <div class="space-y-3">
                <label class="block">
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">API base URL</span>
                    <input id="baseUrl" type="text" class="input-app input-app-sm font-mono" placeholder="http://127.0.0.1:9000" autocomplete="off" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">Relay WebSocket</span>
                    <input id="relayWsUrl" type="text" class="input-app input-app-sm font-mono" placeholder="ws://127.0.0.1:9001" autocomplete="off" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">Default meeting title</span>
                    <input id="meetingTitle" type="text" class="input-app" placeholder="Team sync" />
                </label>
            </div>
            <button id="btnCheckApi" type="button" class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 text-sm font-semibold text-slate-800 active:bg-slate-100">Refresh connection status</button>
        </div>
    </details>

    <section class="overflow-hidden rounded-2xl border border-slate-200/90 bg-white p-5 shadow-lg shadow-slate-900/[0.06]">
        <div class="mb-4 flex items-center justify-between gap-2">
            <h2 class="text-base font-bold text-slate-900">Account</h2>
            <div id="authStatus" class="max-w-[55%] truncate text-right text-xs font-medium text-slate-500">Not signed in</div>
        </div>
        <div id="authForms" class="space-y-4">
            <div class="grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1">
                <button id="tabLogin" type="button" class="rounded-lg py-2.5 text-sm font-semibold shadow-sm transition bg-white text-slate-900">Sign in</button>
                <button id="tabSignup" type="button" class="rounded-lg py-2.5 text-sm font-semibold text-slate-600 transition">Create account</button>
            </div>
            <div id="formLogin" class="space-y-3">
                <label class="block">
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">Email</span>
                    <input id="loginEmail" type="email" class="input-app" placeholder="you@company.com" autocomplete="username" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">Password</span>
                    <input id="loginPassword" type="password" class="input-app" placeholder="••••••••" autocomplete="current-password" />
                </label>
                <button id="btnLogin" type="button" class="mt-1 w-full rounded-xl bg-gradient-to-b from-cyan-400 to-[#1AD0DE] py-3.5 text-sm font-bold text-slate-900 shadow-md shadow-cyan-500/25 active:opacity-90">Sign in</button>
            </div>
            <div id="formSignup" class="hidden space-y-3">
                <label class="block">
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">Name</span>
                    <input id="signupName" type="text" class="input-app" placeholder="Your name" autocomplete="name" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">Email</span>
                    <input id="signupEmail" type="email" class="input-app" placeholder="you@company.com" autocomplete="email" />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">Password</span>
                    <input id="signupPassword" type="password" class="input-app" placeholder="8+ characters" autocomplete="new-password" />
                </label>
                <button id="btnSignup" type="button" class="mt-1 w-full rounded-xl bg-gradient-to-b from-emerald-400 to-emerald-600 py-3.5 text-sm font-bold text-white shadow-md shadow-emerald-500/25 active:opacity-90">Create account</button>
            </div>
        </div>
        <div id="authLoggedIn" class="hidden flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 text-sm text-slate-700">
                <span class="font-semibold text-slate-900" id="authUserName"></span>
                <div class="mt-1 truncate font-mono text-[0.65rem] text-slate-400" id="authTokenShort"></div>
            </div>
            <button id="btnLogout" type="button" class="shrink-0 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-700 active:bg-red-100">Sign out</button>
        </div>
    </section>

    <section id="panelCreate" class="overflow-hidden rounded-2xl border border-slate-200/90 bg-white p-5 shadow-lg shadow-slate-900/[0.06]">
        <h2 class="text-lg font-bold leading-snug text-slate-900">New meeting</h2>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">Create the room, capture short voice intros, then start the live session.</p>
        <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5 active:bg-slate-100">
            <input id="useWs" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-300 text-cyan-500 focus:ring-cyan-400" checked />
            <span class="text-sm font-medium leading-snug text-slate-800">Use WebSocket for lowest-latency live audio</span>
        </label>
        <button id="btnCreateMeeting" type="button" class="mt-5 w-full rounded-xl bg-gradient-to-b from-cyan-400 to-[#1AD0DE] py-4 text-base font-bold text-slate-900 shadow-lg shadow-cyan-500/25 disabled:opacity-40 disabled:shadow-none" disabled>Create meeting</button>
    </section>

    <!-- Step 1: Intro enrollment -->
    <div id="stepIntro" class="hidden space-y-5 rounded-2xl border border-slate-200/90 bg-white p-5 shadow-lg shadow-slate-900/[0.06]">
        <div class="space-y-3">
            <div class="flex flex-row items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-[0.65rem] font-bold uppercase tracking-wider text-slate-400">Step 1 of 2</p>
                    <h2 class="mt-1 text-xl font-bold leading-snug text-slate-900">Participant introductions</h2>
                </div>
                <div class="shrink-0 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-center">
                    <div class="text-[0.6rem] font-semibold uppercase tracking-wide text-slate-500">Meeting</div>
                    <div id="meetingIdLabel" class="font-mono text-lg font-bold leading-none text-cyan-700">–</div>
                </div>
            </div>
            <p class="text-sm leading-relaxed text-slate-600">Everyone says <strong class="font-semibold text-slate-900">“My name is …”</strong> clearly into the mic so we can label voices.</p>
        </div>

        <div class="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
            <button id="btnIntroModeHttp" type="button" class="rounded-lg py-3 text-center text-xs font-bold leading-tight shadow-sm transition bg-white text-slate-900">HTTP<br><span class="font-semibold text-slate-500">chunks</span></button>
            <button id="btnIntroModeWs" type="button" class="rounded-lg py-3 text-center text-xs font-semibold leading-tight text-slate-600 transition">WebSocket<br><span class="font-medium text-slate-500">live</span></button>
        </div>

        <div id="introHttp" class="space-y-4">
            <div class="flex flex-col gap-2">
                <button id="btnIntroEnroll" type="button" class="w-full rounded-xl bg-slate-900 py-3.5 text-sm font-bold text-white shadow-md active:bg-slate-800">● Record intro clip</button>
                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <button id="btnIntroNext" type="button" class="hidden w-full rounded-xl border border-emerald-200 bg-emerald-50 py-3 text-sm font-bold text-emerald-900 sm:w-auto sm:min-w-[10rem]">+ Add another person</button>
                    <button id="btnIntroRetry" type="button" class="hidden w-full rounded-xl border border-amber-200 bg-amber-50 py-3 text-sm font-bold text-amber-900 sm:w-auto sm:min-w-[10rem]">↺ Try again</button>
                </div>
                <label class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <span class="text-sm font-medium text-slate-700">Clip length (seconds)</span>
                    <input id="introSeconds" type="text" inputmode="numeric" class="w-16 rounded-lg border border-slate-200 bg-white px-2 py-2 text-center font-mono text-sm font-semibold" value="6" />
                </label>
            </div>
            <div class="rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-xs leading-relaxed text-cyan-950">
                Audio is processed with <strong>Deepgram</strong> for speaker labels, then names are saved for this meeting.
            </div>
        </div>

        <div id="introWs" class="hidden space-y-3">
            <div class="space-y-2 rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 text-xs leading-relaxed text-violet-950">
                <p class="font-bold text-violet-900">Live WebSocket intro</p>
                <p>After you tap <em>Start meeting</em>, each person should say <strong>“My name is …”</strong> at the beginning so labels map correctly.</p>
                <p class="text-violet-800/90">If you already used HTTP clips, repeat the same name in the live session so we can merge them.</p>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <span class="text-sm font-bold text-slate-900">Enrolled</span>
                    <button id="btnRefreshParticipants" type="button" class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-cyan-700 shadow-sm ring-1 ring-slate-200 active:bg-slate-50">Refresh</button>
                </div>
                <div id="participantList" class="space-y-2">
                    <div class="text-sm text-slate-500">No one enrolled yet.</div>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 p-4">
                <div class="mb-2 text-sm font-bold text-slate-900">Activity</div>
                <pre id="introLog" class="max-h-44 overflow-auto rounded-lg bg-slate-950 p-3 font-mono text-[0.7rem] leading-relaxed text-slate-100"></pre>
            </div>
        </div>

        <div class="flex flex-col gap-3 border-t border-slate-100 pt-4">
            <label class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                <span class="text-sm font-medium text-slate-700">Chunk size (ms)</span>
                <input id="meetingChunkMs" type="text" inputmode="numeric" class="w-24 rounded-lg border border-slate-200 bg-white px-2 py-2 text-center font-mono text-sm font-semibold" value="5000" />
            </label>
            <button id="btnStartMeeting" type="button" class="w-full rounded-xl bg-gradient-to-b from-emerald-500 to-emerald-700 py-4 text-base font-bold text-white shadow-lg shadow-emerald-600/25 disabled:opacity-40 disabled:shadow-none" disabled>Start meeting →</button>
        </div>
    </div>

    <!-- Step 2: Live meeting -->
    <div id="stepMeeting" class="hidden space-y-4">
        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-lg shadow-slate-900/[0.06]">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h2 id="meetingTitleLabel" class="text-lg font-bold text-slate-900">Meeting</h2>
                    <div id="elapsed" class="mt-2 text-4xl font-bold tabular-nums tracking-tight text-slate-900">00:00:00</div>
                </div>
                <div class="flex flex-col items-stretch gap-2 sm:items-end">
                    <div id="statusPill" class="inline-flex items-center justify-center gap-2 self-start rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-semibold text-emerald-900 sm:self-end">
                        <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span id="statusText">Recording</span>
                    </div>
                    <div id="wsStatusBadge" class="hidden text-right text-[0.65rem] font-mono text-slate-400"></div>
                </div>
            </div>
            <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <button id="btnPause" type="button" class="w-full rounded-xl border border-slate-200 bg-slate-100 py-3 text-sm font-semibold text-slate-900 sm:w-auto sm:min-w-[8rem]">Pause</button>
                <button id="btnResume" type="button" class="hidden w-full rounded-xl bg-slate-900 py-3 text-sm font-semibold text-white sm:w-auto sm:min-w-[8rem]">Resume</button>
                <button id="btnEnd" type="button" class="w-full rounded-xl bg-red-600 py-3 text-sm font-bold text-white shadow-md shadow-red-600/20 sm:ml-auto sm:w-auto sm:min-w-[10rem]">End meeting</button>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-lg shadow-slate-900/[0.06]">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <span class="text-base font-bold text-slate-900">Talk time</span>
                <span class="text-xs font-medium text-slate-500">Crosstalk <strong class="text-slate-800"><span id="crosstalkPct">0</span>%</strong></span>
            </div>
            <div id="bars" class="space-y-3">
                <div class="text-sm text-slate-500">Waiting for speech…</div>
            </div>
            <div class="mt-4 border-t border-slate-100 pt-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm font-bold text-slate-900">Voice match</span>
                    <span id="voiceConfig" class="max-w-[55%] truncate font-mono text-[0.65rem] text-slate-500">–</span>
                </div>
                <div id="voiceMatching" class="mt-2 font-mono text-[0.7rem] leading-relaxed whitespace-pre-wrap text-slate-600">–</div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-lg shadow-slate-900/[0.06]">
            <div class="mb-3 flex items-center justify-between gap-2">
                <span class="text-base font-bold text-slate-900">Live transcript</span>
                <span id="transcriptMode" class="text-[0.65rem] font-medium text-slate-400"></span>
            </div>
            <div id="liveTranscript" class="min-h-[5rem] space-y-2">
                <div class="text-sm text-slate-500">Transcript appears here…</div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-lg shadow-slate-900/[0.06]">
            <div class="mb-2 text-sm font-bold text-slate-900">Event log</div>
            <pre id="eventLog" class="max-h-40 overflow-auto rounded-lg bg-slate-950 p-3 font-mono text-[0.7rem] leading-relaxed text-slate-100"></pre>
        </div>
    </div>

    <!-- Step 3: Analytics -->
    <div id="stepAnalytics" class="hidden space-y-5 rounded-2xl border border-slate-200/90 bg-white p-5 shadow-lg shadow-slate-900/[0.06]">
        <div>
            <p class="text-[0.65rem] font-bold uppercase tracking-wider text-slate-400">Meeting ended</p>
            <h2 class="mt-1 text-xl font-bold text-slate-900">Insights</h2>
        </div>
        <div id="analyticsContent" class="space-y-4">
            <div class="text-sm text-slate-500">Loading…</div>
        </div>
        <button id="btnNewMeeting" type="button" class="w-full rounded-xl bg-gradient-to-b from-cyan-400 to-[#1AD0DE] py-4 text-base font-bold text-slate-900 shadow-lg shadow-cyan-500/25">New meeting</button>
    </div>

</main>

@php
    $wcMeetingDemoDefaults = [
        'apiBase' => rtrim((string) config('app.url'), '/'),
        'relayWs' => (string) config('services.wechirp.relay_ws_url'),
    ];
@endphp
<script>
window.__WC_DEMO_DEFAULTS__ = @json($wcMeetingDemoDefaults);
</script>
<script>
const $ = (id) => document.getElementById(id);

// ─────────────────────────── State ───────────────────────────
const state = {
    token: '',
    userName: '',
    meetingId: null,
    introMode: 'http',          // 'http' | 'ws'
    introChunkIndex: 0,
    participants: [],
    introEnrolling: false,
    introWaitingNext: false,
    meeting: {
        running: false, paused: false, chunkIndex: 0, startedAt: null, timer: null,
        stream: null, recorder: null,
        audioCtx: null,
    },
    ws: { socket: null, connected: false, useServerAudioClock: false },
    sse: null,
    statsPollTimer: null,
    audio: { lastSentAt: 0, keepaliveTimer: null },
};

// ─────────────────────────── Fast polling (0.5s) ───────────────────────────
function startStatsPolling() {
    stopStatsPolling();
    if (!state.meetingId) return;

    // Poll DB-backed stats so bars refresh even if WS is slower.
    state.statsPollTimer = setInterval(async () => {
        try {
            if (!state.meeting.running || state.meeting.paused) return;
            const payload = await api(`/api/meetings/${state.meetingId}/stats`);
            renderBars(payload);
        } catch {}
    }, 500);
}

function stopStatsPolling() {
    if (state.statsPollTimer) {
        try { clearInterval(state.statsPollTimer); } catch {}
        state.statsPollTimer = null;
    }
}

// ─────────────────────────── Persist ───────────────────────────
function loadPrefs() {
    const D = window.__WC_DEMO_DEFAULTS__ || {};
    const origin = (typeof window !== 'undefined' && window.location && window.location.origin)
        ? window.location.origin
        : '';
    $('baseUrl').value       = localStorage.getItem('wc.baseUrl')       || origin || D.apiBase || 'http://127.0.0.1:9000';
    $('relayWsUrl').value    = localStorage.getItem('wc.relayWsUrl')    || D.relayWs || 'ws://127.0.0.1:9001';
    $('meetingTitle').value  = localStorage.getItem('wc.meetingTitle')  || 'Team Sync Q1';
    $('introSeconds').value  = localStorage.getItem('wc.introSeconds')  || '6';
    $('meetingChunkMs').value= localStorage.getItem('wc.chunkMs')       || '5000';
    $('useWs').checked       = (localStorage.getItem('wc.useWs') ?? '1') === '1';

    state.token    = localStorage.getItem('wc.token')    || '';
    state.userName = localStorage.getItem('wc.userName') || '';
    if (state.token) showLoggedIn();
}
function savePrefs() {
    localStorage.setItem('wc.baseUrl',      $('baseUrl').value.trim());
    localStorage.setItem('wc.relayWsUrl',   $('relayWsUrl').value.trim());
    localStorage.setItem('wc.meetingTitle', $('meetingTitle').value.trim());
    localStorage.setItem('wc.introSeconds', $('introSeconds').value.trim());
    localStorage.setItem('wc.chunkMs',      $('meetingChunkMs').value.trim());
    localStorage.setItem('wc.useWs',        $('useWs').checked ? '1' : '0');
}
['baseUrl','relayWsUrl','meetingTitle','introSeconds','meetingChunkMs','useWs']
    .forEach(id => $(id)?.addEventListener('change', savePrefs));

// ─────────────────────────── API helper ───────────────────────────
async function api(path, { method = 'GET', headers = {}, body } = {}) {
    const base = $('baseUrl').value.trim().replace(/\/$/, '');
    const h = { ...headers };
    if (state.token) h['Authorization'] = `Bearer ${state.token}`;
    const res = await fetch(base + path, { method, headers: h, body });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch {}
    if (!res.ok) {
        const msg = json?.message || json?.error || text;
        throw new Error(`HTTP ${res.status}: ${String(msg).slice(0, 200)}`);
    }
    return json ?? text;
}

// ─────────────────────────── Status badges ───────────────────────────
async function checkConnections() {
    const base = $('baseUrl').value.trim().replace(/\/$/, '');

    // API
    try {
        const r = await fetch(base + '/api/faqs', { signal: AbortSignal.timeout(3000) });
        badge('badgeApi', r.ok, 'API');
    } catch { badge('badgeApi', false, 'API'); }

    // WS relay
    try {
        const wsBase = $('relayWsUrl').value.trim().replace(/\/$/, '').replace(/^ws/, 'http');
        const r = await fetch(wsBase + '/up', { signal: AbortSignal.timeout(3000) });
        badge('badgeWs', r.ok, 'Relay');
    } catch { badge('badgeWs', false, 'Relay'); }

    badge('badgeDeepgram', true, 'STT');
}

function badge(id, ok, label) {
    const el = $(id);
    el.textContent = `${label}: ${ok ? '✓' : '✗'}`;
    el.className = 'pill ' + (ok
        ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
        : 'bg-red-50 text-red-700 border border-red-200');
}

// ─────────────────────────── Auth ───────────────────────────
$('tabLogin').addEventListener('click', () => {
    $('formLogin').classList.remove('hidden');
    $('formSignup').classList.add('hidden');
    $('tabLogin').className  = 'rounded-lg py-2.5 text-sm font-semibold shadow-sm transition bg-white text-slate-900';
    $('tabSignup').className = 'rounded-lg py-2.5 text-sm font-semibold text-slate-600 transition';
});
$('tabSignup').addEventListener('click', () => {
    $('formSignup').classList.remove('hidden');
    $('formLogin').classList.add('hidden');
    $('tabSignup').className = 'rounded-lg py-2.5 text-sm font-semibold shadow-sm transition bg-white text-slate-900';
    $('tabLogin').className  = 'rounded-lg py-2.5 text-sm font-semibold text-slate-600 transition';
});

$('btnLogin').addEventListener('click', async () => {
    const email    = $('loginEmail').value.trim();
    const password = $('loginPassword').value;
    if (!email || !password) return alert('Email and password required');
    try {
        const res = await api('/api/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password }),
        });
        state.token    = res.data?.token || res.token || '';
        state.userName = res.data?.name  || res.name  || email;
        persistAuth();
        showLoggedIn();
    } catch(e) { alert('Login failed: ' + e.message); }
});

$('btnSignup').addEventListener('click', async () => {
    const name     = $('signupName').value.trim();
    const email    = $('signupEmail').value.trim();
    const password = $('signupPassword').value;
    if (!name || !email || !password) return alert('All fields required');
    try {
        const res = await api('/api/signup', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, email, password, password_confirmation: password }),
        });
        state.token    = res.data?.token || res.token || '';
        state.userName = name;
        persistAuth();
        showLoggedIn();
    } catch(e) { alert('Signup failed: ' + e.message); }
});

$('btnLogout').addEventListener('click', () => {
    state.token = '';
    state.userName = '';
    localStorage.removeItem('wc.token');
    localStorage.removeItem('wc.userName');
    $('authStatus').textContent = 'Not signed in';
    $('authForms').classList.remove('hidden');
    $('authLoggedIn').classList.add('hidden');
    $('btnCreateMeeting').disabled = true;
});

function persistAuth() {
    localStorage.setItem('wc.token',    state.token);
    localStorage.setItem('wc.userName', state.userName);
}

function showLoggedIn() {
    $('authForms').classList.add('hidden');
    $('authLoggedIn').classList.remove('hidden');
    $('authUserName').textContent  = state.userName;
    $('authTokenShort').textContent = state.token.slice(0, 12) + '…';
    $('authStatus').textContent = 'Signed in';
    $('btnCreateMeeting').disabled = false;
}

// ─────────────────────────── Intro mode toggle ───────────────────────────
$('btnIntroModeHttp').addEventListener('click', () => {
    state.introMode = 'http';
    $('introHttp').classList.remove('hidden');
    $('introWs').classList.add('hidden');
    $('btnIntroModeHttp').className = 'rounded-lg py-3 text-center text-xs font-bold leading-tight shadow-sm transition bg-white text-slate-900';
    $('btnIntroModeWs').className   = 'rounded-lg py-3 text-center text-xs font-semibold leading-tight text-slate-600 transition';
    updateStartBtn();
});
$('btnIntroModeWs').addEventListener('click', () => {
    state.introMode = 'ws';
    $('introWs').classList.remove('hidden');
    $('introHttp').classList.add('hidden');
    $('btnIntroModeWs').className   = 'rounded-lg py-3 text-center text-xs font-bold leading-tight shadow-sm transition bg-violet-600 text-white';
    $('btnIntroModeHttp').className = 'rounded-lg py-3 text-center text-xs font-semibold leading-tight text-slate-600 transition';
    // WS intro: always enable Start (intro happens in session)
    $('btnStartMeeting').disabled = false;
});

// ─────────────────────────── Meeting creation ───────────────────────────
$('btnCreateMeeting').addEventListener('click', async () => {
    savePrefs();
    try {
        const title = $('meetingTitle').value.trim() || 'Meeting';
        const out = await api('/api/meetings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title }),
        });
        state.meetingId       = out.id;
        state.introChunkIndex = 0;
        state.participants    = [];
        $('meetingIdLabel').textContent  = String(out.id);
        $('meetingTitleLabel').textContent = title;
        logIntro(`meeting created (id=${out.id})`);
        $('stepIntro').classList.remove('hidden');
        renderParticipants();
        updateStartBtn();
    } catch(e) { alert('Create meeting failed: ' + e.message); }
});

// ─────────────────────────── Participants ───────────────────────────
function isPlaceholder(name) {
    return /^Speaker\s+\d+$/i.test(name) || /^speaker_\d+$/i.test(name);
}

function renderParticipants() {
    const wrap = $('participantList');
    wrap.innerHTML = '';
    const real = state.participants.filter(p => !isPlaceholder(p.name));
    if (real.length === 0) {
        wrap.innerHTML = '<div class="text-sm text-slate-500">No one enrolled yet.</div>';
        return;
    }
    real.forEach(p => {
        const ve = p.voice_embedding || {};
        const provider = ve.provider || '–';
        const type     = ve.type     || '–';
        const label    = ve.speaker_label || '–';
        const enrolledAt = ve.enrolled_at
            ? new Date(ve.enrolled_at).toLocaleTimeString()
            : null;

        const el = document.createElement('div');
        el.className = 'rounded-xl border border-slate-200 bg-white px-3 py-3 space-y-2 shadow-sm';
        el.innerHTML = `
            <div class="flex items-center justify-between gap-2">
                <div class="font-semibold text-sm">${p.name}</div>
                <span class="pill bg-emerald-50 text-emerald-700 border border-emerald-200">Enrolled ✓</span>
            </div>
            <div class="flex flex-wrap gap-1.5 text-xs">
                <span class="pill bg-blue-50 text-blue-700">provider: ${provider}</span>
                <span class="pill bg-slate-100 text-slate-600">type: ${type}</span>
                ${label !== '–' ? `<span class="pill bg-purple-50 text-purple-700">label: ${label}</span>` : ''}
                ${enrolledAt ? `<span class="pill bg-slate-50 text-slate-500">enrolled: ${enrolledAt}</span>` : ''}
            </div>
        `;
        wrap.appendChild(el);
    });
}

async function refreshParticipants() {
    if (!state.meetingId) return;
    const out = await api(`/api/meetings/${state.meetingId}/participants`);
    state.participants = (Array.isArray(out?.data) ? out.data : []).map(p => ({
        id: p.id, name: p.name, voice_embedding: p.voice_embedding ?? null,
    }));
    renderParticipants();
    updateStartBtn();
}

$('btnRefreshParticipants').addEventListener('click', () => refreshParticipants().catch(e => logIntro('Refresh failed: ' + e.message)));

function updateStartBtn() {
    if (state.introMode === 'ws') {
        $('btnStartMeeting').disabled = !state.meetingId;
        return;
    }
    const enrolled = state.participants.filter(p => !isPlaceholder(p.name)).length;
    $('btnStartMeeting').disabled = !state.meetingId || enrolled === 0;
}

// ─────────────────────────── Intro enrollment (HTTP) ───────────────────────────
function logIntro(line) {
    const t = `[${new Date().toLocaleTimeString()}] ${line}`;
    $('introLog').textContent = t + '\n' + $('introLog').textContent;
}

function introUi(mode) {
    $('btnIntroEnroll').classList.toggle('hidden', mode !== 'idle');
    $('btnIntroNext').classList.toggle('hidden', mode !== 'done');
    $('btnIntroRetry').classList.toggle('hidden', mode !== 'retry');
}

async function enrollOneParticipant() {
    if (!state.meetingId) throw new Error('Create a meeting first');
    if (state.introEnrolling || state.introWaitingNext) return;
    state.introEnrolling = true;
    introUi('recording');

    // Enrollment must be quick on mobile; user chooses duration.
    // We enforce min/max in the UI so backend can embed the same window.
    const seconds = Math.max(3, Math.min(6, parseInt($('introSeconds').value, 10) || 6));
    logIntro(`▶ Recording ${seconds}s — say: "My name is [name]"`);

    const beforeNames = new Set(state.participants.filter(p => !isPlaceholder(p.name)).map(p => p.name.toLowerCase()));

    // Ask browser to apply common voice-processing so enrollment doesn't require shouting.
    const stream  = await navigator.mediaDevices.getUserMedia({
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
        }
    });
    const mime    = MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')
        ? 'audio/ogg;codecs=opus'
        : MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
            ? 'audio/webm;codecs=opus' : 'audio/webm';

    const recorder = new MediaRecorder(stream, { mimeType: mime });
    const parts = [];
    recorder.ondataavailable = ev => { if (ev.data?.size > 0) parts.push(ev.data); };

    await new Promise(r => { recorder.onstart = r; recorder.start(); });
    await new Promise(r => setTimeout(r, seconds * 1000));
    try { recorder.stop(); } catch {}
    const blob = await new Promise(r => { recorder.onstop = () => r(new Blob(parts, { type: mime })); });
    stream.getTracks().forEach(t => t.stop());

    const ext = mime.includes('ogg') ? 'ogg' : 'webm';
    logIntro(`⏫ Uploading ${blob.size} bytes (chunk #${state.introChunkIndex}) → Deepgram`);

    const fd = new FormData();
    fd.append('chunk_index', String(state.introChunkIndex++));
    fd.append('duration_seconds', String(seconds));
    fd.append('audio', blob, `intro.${ext}`);

    // sync=1 runs Deepgram+analyzer in the HTTP request (no queue worker needed for local demos).
    const res = await api(`/api/meetings/${state.meetingId}/intro/chunk?sync=1`, { method: 'POST', body: fd });

    if (res?.status === 'failed') throw new Error(String(res.error || 'analyzer_failed'));

    const heardLines = Array.isArray(res?.heard) ? res.heard.filter((h) => h?.text && String(h.text).trim()) : [];
    logIntro(
        heardLines.length > 0
            ? `✓ Processing finished — ${heardLines.length} speech line(s) from this clip.`
            : '✓ Processing finished — no transcribed speech in this clip (silence, levels, or noise).',
    );

    // Fast-path: use server response to update UI instantly.
    const enrolled = Array.isArray(res?.enrolled_participants) ? res.enrolled_participants : [];
    if (enrolled.length > 0) {
        // Merge into current state without waiting for another HTTP call.
        const byId = new Map(state.participants.map(p => [String(p.id), p]));
        enrolled.forEach(p => {
            const id = String(p.id);
            byId.set(id, { id: p.id, name: p.name, voice_embedding: p.voice_embedding ?? null });
        });
        state.participants = Array.from(byId.values());
        renderParticipants();
        updateStartBtn();
    }

    // Backstop: refresh list once (handles cases where participant was renamed/merged).
    await refreshParticipants();
    const newNames = state.participants.filter(p => !isPlaceholder(p.name) && !beforeNames.has(p.name.toLowerCase()));

    state.introEnrolling = false;
    if (newNames.length > 0) {
        state.introWaitingNext = true;
        logIntro(`✅ Enrolled: ${newNames.map(p => p.name).join(', ')}`);
        introUi('done');
    } else {
        logIntro('⚠ Still processing or no name detected yet — please wait a bit or try again.');
        introUi('retry');
    }
}

$('btnIntroEnroll').addEventListener('click', async () => {
    try {
        state.introEnrolling = false;
        state.introWaitingNext = false;
        await enrollOneParticipant();
    } catch(e) {
        state.introEnrolling = false;
        introUi('idle');
        logIntro('✗ Error: ' + (e?.message ?? e));
        alert(e?.message ?? 'Enroll failed');
    }
});
$('btnIntroNext').addEventListener('click', () => {
    state.introWaitingNext = false;
    introUi('idle');
    logIntro('— next participant: say your name now —');
});
$('btnIntroRetry').addEventListener('click', async () => {
    try {
        state.introEnrolling = false;
        state.introWaitingNext = false;
        await enrollOneParticipant();
    } catch(e) {
        state.introEnrolling = false;
        introUi('idle');
        logIntro('✗ ' + (e?.message ?? e));
    }
});

// ─────────────────────────── Meeting start ───────────────────────────
$('btnStartMeeting').addEventListener('click', async () => {
    try {
        state.introEnrolling    = false;
        state.introWaitingNext  = false;

        await api(`/api/meetings/${state.meetingId}/start`, { method: 'POST' });

        $('stepIntro').classList.add('hidden');
        $('stepMeeting').classList.remove('hidden');
        $('stepAnalytics').classList.add('hidden');

        state.meeting.startedAt = Date.now();
        state.meeting.running   = true;
        state.meeting.paused    = false;
        state.meeting.chunkIndex = 0;

        if (state.meeting.timer) clearInterval(state.meeting.timer);
        state.meeting.timer = setInterval(() => {
            $('elapsed').textContent = formatElapsed(Date.now() - state.meeting.startedAt);
        }, 250);

        const useWs = $('useWs').checked;
        if (useWs) {
            if (state.meeting.timer) {
                clearInterval(state.meeting.timer);
                state.meeting.timer = null;
            }
            state.ws.useServerAudioClock = true;
            startWs();
            $('transcriptMode').textContent = 'Bars only (WebSocket realtime)';
            $('liveTranscript').innerHTML = '<div class="text-xs text-slate-400">Live transcript disabled for ultra-low latency mode.</div>';
        } else {
            startHttpChunks();
            startSse();
            $('transcriptMode').textContent = 'Polling (HTTP chunks)';
        }

        // In WS mode, bars update via relay pushes (stats.updated).
        // In HTTP chunk mode, we also poll DB stats every 0.5s for smoother UI.
        if (!useWs) {
            startStatsPolling();
        }
    } catch(e) { alert('Start meeting failed: ' + e.message); }
});

// ─────────────────────────── WebSocket live ───────────────────────────
/** Merge rapid stats.updated messages into one paint per animation frame. */
function scheduleWsStatsFrame(data) {
    scheduleWsStatsFrame._pending = data;
    if (scheduleWsStatsFrame._id != null) return;
    scheduleWsStatsFrame._id = requestAnimationFrame(() => {
        scheduleWsStatsFrame._id = null;
        const p = scheduleWsStatsFrame._pending;
        scheduleWsStatsFrame._pending = null;
        if (p) {
            renderBars(p);
            renderVoiceDebug(p);
            if (state.ws.useServerAudioClock && state.meeting.running) {
                const live = Number(p.live_audio_seconds);
                if (Number.isFinite(live) && live >= 0) {
                    $('elapsed').textContent = formatElapsed(live * 1000);
                }
            }
        }
    });
}

function startWs() {
    const token  = state.token;
    const base   = $('relayWsUrl').value.trim().replace(/\/$/, '');
    // Chrome test mode: stream raw PCM16 (16kHz mono) to avoid WebM/Opus chunk issues.
    const wsUrl  = `${base}/meetings/${state.meetingId}/live?format=pcm16&transcript=0&auth=post`;
    const ws     = new WebSocket(wsUrl);
    state.ws.socket = ws;

    $('wsStatusBadge').classList.remove('hidden');
    $('wsStatusBadge').textContent = 'WS: connecting…';

    ws.onopen = () => {
        try { ws.send(JSON.stringify({ type: 'auth', token })); } catch {}
        state.ws.connected = true;
        logEvent('WS connected ✓');
        $('wsStatusBadge').textContent = 'WS: connected ✓';

        // Keepalive: send silence periodically if audio pipeline stalls.
        if (state.audio.keepaliveTimer) clearInterval(state.audio.keepaliveTimer);
        state.audio.lastSentAt = Date.now();
        state.audio.keepaliveTimer = setInterval(() => {
            if (!state.meeting.running) return;
            if (ws.readyState !== WebSocket.OPEN) return;
            const since = Date.now() - (state.audio.lastSentAt || 0);
            if (since < 2000) return; // audio is flowing
            // Send 100ms of silence PCM16 @ 16kHz mono (1600 samples = 3200 bytes)
            try {
                ws.send(new ArrayBuffer(3200));
                state.audio.lastSentAt = Date.now();
            } catch {}
        }, 1000);
    };
    ws.onerror = (ev) => {
        logEvent('WS error (see console for details)');
        $('wsStatusBadge').textContent = 'WS: error ✗';
        try { console.error('WS error event', ev); } catch {}
    };
    ws.onclose = (ev) => {
        state.ws.connected = false;
        const code = (ev && typeof ev.code === 'number') ? ev.code : 'n/a';
        const reason = (ev && typeof ev.reason === 'string') ? ev.reason : '';
        const clean = (ev && typeof ev.wasClean === 'boolean') ? ev.wasClean : false;
        logEvent(`WS closed (code=${code} clean=${clean} reason=${reason})`);
        $('wsStatusBadge').textContent = 'WS: closed';
    };
    ws.onmessage = ev => {
        try {
            const msg = JSON.parse(String(ev.data || ''));
            if (msg?.error) { logEvent(`WS error: ${msg.error}${msg.message ? ' – ' + msg.message : ''}`); return; }
            if (msg?.event === 'stats.updated') scheduleWsStatsFrame(msg.data);
            if (msg?.event === 'transcript.updated') renderLiveLines(msg.data?.lines || []);
        } catch {}
    };

    startWsPcm(ws).catch(e => logEvent('Mic PCM error: ' + e.message));
}

async function startWsPcm(ws) {
    const stream = await navigator.mediaDevices.getUserMedia({
        audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
        }
    });
    state.meeting.stream = stream;

    if (!window.AudioWorkletNode) {
        throw new Error('AudioWorklet not supported in this browser');
    }

    // Force 16kHz AudioContext in Chrome to avoid resampling drift/quality loss.
    const AudioCtx = (window.AudioContext || window.webkitAudioContext);
    const ctx = new AudioCtx({ sampleRate: 16000 });
    state.meeting.audioCtx = ctx;

    // Worklet: just forwards Float32 frames; conversion/resample happens on main thread.
    const workletCode = `
        class WcPcmTap extends AudioWorkletProcessor {
          process(inputs) {
            const input = inputs[0];
            const ch0 = input && input[0];
            if (ch0 && ch0.length) {
              // Copy because underlying buffer is reused by AudioWorklet.
              this.port.postMessage(ch0.slice(0));
            }
            return true;
          }
        }
        registerProcessor('wc-pcm-tap', WcPcmTap);
    `;
    const blobUrl = URL.createObjectURL(new Blob([workletCode], { type: 'application/javascript' }));
    await ctx.audioWorklet.addModule(blobUrl);
    URL.revokeObjectURL(blobUrl);

    const source = ctx.createMediaStreamSource(stream);
    const node = new AudioWorkletNode(ctx, 'wc-pcm-tap');
    // Avoid feedback: do not connect to destination.
    source.connect(node);

    function floatToPcm16LE(f32) {
        const out = new Int16Array(f32.length);
        for (let i = 0; i < f32.length; i++) {
            const x = Math.max(-1, Math.min(1, f32[i]));
            out[i] = x < 0 ? (x * 0x8000) : (x * 0x7FFF);
        }
        return out.buffer;
    }

    node.port.onmessage = (ev) => {
        // IMPORTANT: keep the WS + Deepgram connection alive even during silence/paused.
        // If paused, we send silence frames instead of stopping audio entirely.
        if (!state.meeting.running) return;
        if (ws.readyState !== WebSocket.OPEN) return;

        const chunk = ev.data;
        if (!(chunk instanceof Float32Array) || chunk.length === 0) return;

        // AudioContext is fixed at 16kHz, so frame to ~40ms for lower latency.
        // Keep a small remainder buffer for clean framing.
        const frameSamples = 640; // 40ms @ 16k
        if (!startWsPcm._buf) startWsPcm._buf = new Float32Array(0);
        const prev = startWsPcm._buf;
        const src = new Float32Array(prev.length + chunk.length);
        src.set(prev, 0);
        src.set(chunk, prev.length);

        let offset = 0;
        while (offset + frameSamples <= src.length) {
            const frame = src.subarray(offset, offset + frameSamples);
            const toSend = state.meeting.paused ? new Float32Array(frameSamples) : frame;
            try {
                ws.send(floatToPcm16LE(toSend));
                state.audio.lastSentAt = Date.now();
            } catch {}
            offset += frameSamples;
        }
        startWsPcm._buf = src.subarray(offset);
    };

    logEvent('Mic streaming started (PCM16 16k mono) via WebSocket');
    if (state.introMode === 'ws') {
        logEvent('🎤 Intro mode: each participant say "My name is [name]" now, then continue normally.');
    }
}

// ─────────────────────────── HTTP chunk meeting ───────────────────────────
function startHttpChunks() {
    navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
        state.meeting.stream = stream;
        const chunkMs = Math.max(2000, Math.min(15000, parseInt($('meetingChunkMs').value, 10) || 5000));
        const mime = MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')
            ? 'audio/ogg;codecs=opus'
            : MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                ? 'audio/webm;codecs=opus' : 'audio/webm';

        logEvent(`Mic HTTP chunks started (${chunkMs}ms, ${mime})`);

        (async () => {
            while (state.meeting.running) {
                if (state.meeting.paused) { await sleep(200); continue; }

                const rec   = new MediaRecorder(stream, { mimeType: mime });
                const parts = [];
                rec.ondataavailable = ev => { if (ev.data?.size > 0) parts.push(ev.data); };
                await new Promise(r => { rec.onstart = r; rec.start(); });
                await sleep(chunkMs);
                try { rec.stop(); } catch {}
                const blob = await new Promise(r => { rec.onstop = () => r(new Blob(parts, { type: mime })); });
                if (!state.meeting.running) break;
                if (!blob || blob.size === 0) { logEvent('Empty chunk – skipping'); continue; }

                const ext = mime.includes('ogg') ? 'ogg' : 'webm';
                const fd  = new FormData();
                fd.append('meeting_id', String(state.meetingId));
                fd.append('chunk_index', String(state.meeting.chunkIndex++));
                fd.append('audio', blob, `chunk.${ext}`);

                try {
                    logEvent(`Uploading chunk #${state.meeting.chunkIndex - 1} (${blob.size}B)`);
                    const res = await api('/api/audio/chunk?sync=1', { method: 'POST', body: fd });
                    if (res?.status === 'failed') logEvent('Chunk failed: ' + res.error);
                    else await refreshDbTranscripts();
                } catch(e) { logEvent('Chunk error: ' + e.message); await sleep(500); }
            }
        })();
    }).catch(e => logEvent('Mic error: ' + e.message));
}

// ─────────────────────────── SSE ───────────────────────────
function startSse() {
    if (state.sse) state.sse.close();
    const base  = $('baseUrl').value.trim().replace(/\/$/, '');
    const token = state.token;
    const url   = `${base}/api/meetings/${state.meetingId}/stats/stream?token=${encodeURIComponent(token)}`;
    state.sse = new EventSource(url);
    state.sse.addEventListener('stats.updated', async ev => {
        try {
            renderBars(JSON.parse(ev.data));
            logEvent('SSE: stats updated');
        } catch {}
    });
    state.sse.onerror = () => logEvent('SSE error (auto-retry)');
}

async function refreshDbTranscripts() {
    try {
        const tx   = await api(`/api/meetings/${state.meetingId}/transcripts`);
        const items = (tx?.items || []).slice(-6);
        const lines = items.map(i => ({ label: '–', name: '–', text: i.text }));
        if (lines.length > 0) renderLiveLines(lines);
    } catch {}
}

// ─────────────────────────── Render helpers ───────────────────────────
function renderBars(payload) {
    const bars  = $('bars');
    const parts = payload?.participants || [];
    if (parts.length === 0) return;
    // Keep DOM nodes stable so the width transition animates smoothly.
    if (!renderBars._els) renderBars._els = new Map();

    const sorted = [...parts].sort((a, b) => (b.talk_percentage || 0) - (a.talk_percentage || 0));
    const seen = new Set();
    const meetingSeconds = Math.max(0.001, Number(payload?.live_audio_seconds || 0));

    sorted.forEach(p => {
        const pid = Number(p.participant_id || 0);
        const key = pid > 0 ? `pid:${pid}` : `label:${String(p.label || p.name || '')}`;
        seen.add(key);

        const secs = (p.talk_time_seconds != null) ? Number(p.talk_time_seconds) : Number(p.talk_time || 0);
        // For bars-only live mode, show "% of meeting time spoken" (not only % of spoken share).
        const pctMeeting = Math.max(0, Math.min(100, (secs / meetingSeconds) * 100));

        let el = renderBars._els.get(key);
        if (!el) {
            el  = document.createElement('div');
            el.className = 'space-y-1';
            el.innerHTML = `
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium js-name"></span>
                    <span class="text-slate-500 tabular-nums js-meta"></span>
                </div>
                <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-2.5 rounded-full bg-blue-500 transition-[width] duration-150 ease-linear js-bar" style="width:0%"></div>
                </div>
            `;
            renderBars._els.set(key, el);
            bars.appendChild(el);
        } else {
            // Reorder to match sorted order.
            bars.appendChild(el);
        }

        el.querySelector('.js-name').textContent = String(p.name || 'Unknown');
        el.querySelector('.js-meta').textContent = `${pctMeeting.toFixed(0)}% · ${secs.toFixed(1)}s`;
        el.querySelector('.js-bar').style.width = `${pctMeeting}%`;
    });

    // Remove bars that no longer exist.
    for (const [key, el] of renderBars._els.entries()) {
        if (!seen.has(key)) {
            try { el.remove(); } catch {}
            renderBars._els.delete(key);
        }
    }
    $('crosstalkPct').textContent = String(Math.round(payload?.crosstalk_percentage || 0));
}

function renderVoiceDebug(payload) {
    const cfg = payload?.voice_config || null;
    const matching = payload?.voice_matching || null;
    if (!cfg || !matching) return;

    $('voiceConfig').textContent =
        `format=${cfg.format} thr=${Number(cfg.threshold).toFixed(2)} early=${Number(cfg.early_boost).toFixed(2)} win=${cfg.label_window_seconds}s min=${cfg.label_min_speech_seconds}s`;

    const labels = Object.keys(matching || {});
    if (labels.length === 0) {
        $('voiceMatching').textContent = 'No labels yet.';
        return;
    }

    const lines = labels.sort().map(l => {
        const m = matching[l] || {};
        const score = Number(m.best_score || 0).toFixed(3);
        const thr = Number(m.threshold || 0).toFixed(3);
        const ev = Number(m.evidence_count || 0);
        const name = m.best_participant_name || '';
        const ok = m.matched ? 'MATCH' : 'no';
        return `${l}: score=${score} thr=${thr} evidence=${ev} best=${name} => ${ok}`;
    });
    $('voiceMatching').textContent = lines.join('\n');
}

function renderLiveLines(lines) {
    const wrap = $('liveTranscript');
    if (!Array.isArray(lines) || lines.length === 0) return;

    if (!renderLiveLines._els) renderLiveLines._els = new Map();

    const seen = new Set();
    lines.filter(x => String(x.text || '').trim()).forEach(x => {
        const label = String(x.label || '');
        const key = label !== '' ? label : String(x.name || x.label || '');

        seen.add(key);
        let el = renderLiveLines._els.get(key);
        if (!el) {
            el = document.createElement('div');
            el.className = 'flex gap-2 text-sm';
            el.dataset.liveKey = key;
            el.innerHTML = `
                <span class="js-name whitespace-nowrap min-w-[80px] text-right"></span>
                <span class="js-text text-slate-700"></span>
            `;
            renderLiveLines._els.set(key, el);
            wrap.appendChild(el);
        } else {
            wrap.appendChild(el);
        }

        const nameColor = isPlaceholder(x.name || x.label)
            ? 'text-slate-400' : 'text-blue-700 font-semibold';
        const nameEl = el.querySelector('.js-name');
        const textEl = el.querySelector('.js-text');
        nameEl.className = `js-name whitespace-nowrap min-w-[80px] text-right ${nameColor}`;
        nameEl.textContent = x.name || x.label;
        textEl.textContent = x.text;
    });

    for (const [key, el] of renderLiveLines._els.entries()) {
        if (!seen.has(key)) {
            try { el.remove(); } catch {}
            renderLiveLines._els.delete(key);
        }
    }
}

function logEvent(line) {
    const t = `[${new Date().toLocaleTimeString()}] ${line}`;
    $('eventLog').textContent = t + '\n' + $('eventLog').textContent;
}

function formatElapsed(ms) {
    const s  = Math.max(0, Math.floor(ms / 1000));
    const hh = String(Math.floor(s / 3600)).padStart(2, '0');
    const mm = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
    const ss = String(s % 60).padStart(2, '0');
    return `${hh}:${mm}:${ss}`;
}

const sleep = ms => new Promise(r => setTimeout(r, ms));

// ─────────────────────────── Pause / Resume / End ───────────────────────────
$('btnPause').addEventListener('click', () => {
    state.meeting.paused = true;
    $('statusText').textContent = 'Paused';
    $('btnPause').classList.add('hidden');
    $('btnResume').classList.remove('hidden');
});
$('btnResume').addEventListener('click', () => {
    state.meeting.paused = false;
    $('statusText').textContent = 'Recording';
    $('btnResume').classList.add('hidden');
    $('btnPause').classList.remove('hidden');
});

$('btnEnd').addEventListener('click', async () => {
    if (!confirm('End the meeting?')) return;
    stopMic();
    if (state.sse) { state.sse.close(); state.sse = null; }
    if (state.meeting.timer) { clearInterval(state.meeting.timer); state.meeting.timer = null; }

    logEvent('Ending meeting…');
    try {
        const durationSeconds = state.meeting.startedAt
            ? Math.max(0, (Date.now() - state.meeting.startedAt) / 1000)
            : null;
        await api(`/api/meetings/${state.meetingId}/end`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ duration_seconds: durationSeconds }),
        });
    } catch {}

    // Give relay 1.5s to flush final stats then show analytics
    await sleep(1500);
    showAnalytics();
});

function stopMic() {
    state.meeting.running = false;
    stopStatsPolling();
    try { state.meeting.recorder?.stop(); } catch {}
    try { state.meeting.stream?.getTracks().forEach(t => t.stop()); } catch {}
    try { state.meeting.audioCtx?.close(); } catch {}
    try { state.ws.socket?.close(); } catch {}
    try { if (state.audio.keepaliveTimer) clearInterval(state.audio.keepaliveTimer); } catch {}
    state.audio.keepaliveTimer = null;
    state.ws.socket    = null;
    state.ws.connected = false;
    state.ws.useServerAudioClock = false;
    state.meeting.recorder = null;
    state.meeting.stream   = null;
    state.meeting.audioCtx = null;
}

// ─────────────────────────── Analytics ───────────────────────────
async function showAnalytics() {
    $('stepMeeting').classList.add('hidden');
    $('stepAnalytics').classList.remove('hidden');

    try {
        const [analytic, participants, txResp] = await Promise.all([
            api(`/api/meetings/${state.meetingId}/analytics`).catch(() => null),
            api(`/api/meetings/${state.meetingId}/participants`).catch(() => ({ data: [] })),
            api(`/api/meetings/${state.meetingId}/transcripts`).catch(() => ({ items: [] })),
        ]);

        const parts = Array.isArray(participants?.data) ? participants.data : [];
        const real  = parts.filter(p => !isPlaceholder(p.name));
        const analData = analytic?.data || analytic || {};
        const txItems  = (txResp?.items || []).slice(-8);

        let html = '';

        // Participants
        html += `<div class="border rounded-xl p-4 space-y-2">
            <div class="font-semibold text-sm">Participants (${real.length})</div>
            <div class="space-y-1">`;
        real.forEach(p => {
            const ve = p.voice_embedding || {};
            html += `<div class="flex items-center gap-2 text-sm">
                <span class="font-medium">${p.name}</span>
                <span class="pill bg-blue-50 text-blue-700">${ve.provider || 'unknown'}</span>
                <span class="pill bg-slate-100 text-slate-500">${ve.type || '–'}</span>
            </div>`;
        });
        if (real.length === 0) html += '<div class="text-xs text-slate-400">None enrolled.</div>';
        html += '</div></div>';

        // Stats
        if (analData?.participants?.length > 0 || analData?.crosstalk_percentage !== undefined) {
            html += `<div class="border rounded-xl p-4 space-y-3">
                <div class="font-semibold text-sm">Talk time</div>`;
            (analData.participants || []).forEach(p => {
                const pct = Number(p.talk_percentage || 0).toFixed(0);
                html += `<div class="space-y-1">
                    <div class="flex justify-between text-sm">
                        <span class="font-medium">${p.name}</span>
                        <span class="text-slate-500">${pct}% · ${p.talk_time ?? 0}s</span>
                    </div>
                    <div class="h-2 rounded-full bg-slate-100">
                        <div class="h-2 rounded-full bg-blue-500" style="width:${pct}%"></div>
                    </div>
                </div>`;
            });
            html += `<div class="text-xs text-slate-500">Crosstalk: <strong>${Math.round(analData.crosstalk_percentage || 0)}%</strong></div>
            </div>`;
        }

        // NLP / Summary
        if (analData?.summary || analData?.keywords?.length > 0) {
            html += `<div class="border rounded-xl p-4 space-y-2">
                <div class="font-semibold text-sm">Meeting insights</div>`;
            if (analData.keywords?.length > 0) {
                html += `<div class="flex flex-wrap gap-1">
                    ${analData.keywords.map(k => `<span class="pill bg-slate-100 text-slate-700">${k}</span>`).join('')}
                </div>`;
            }
            if (analData.summary) {
                html += `<div class="text-xs text-slate-600 bg-slate-50 rounded p-2">${analData.summary}</div>`;
            }
            if (analData.sentiment) {
                const s = analData.sentiment;
                const color = s.label === 'positive' ? 'emerald' : s.label === 'negative' ? 'red' : 'slate';
                html += `<div class="text-xs">Sentiment: <span class="pill bg-${color}-50 text-${color}-700">${s.label}</span></div>`;
            }
            html += '</div>';
        }

        // Transcripts
        if (txItems.length > 0) {
            html += `<div class="border rounded-xl p-4 space-y-2">
                <div class="font-semibold text-sm">Transcript excerpts</div>
                <pre class="text-xs text-slate-600 whitespace-pre-wrap">${txItems.map(i => i.text).join('\n\n')}</pre>
            </div>`;
        }

        $('analyticsContent').innerHTML = html || '<div class="text-xs text-slate-400">No analytics data yet.</div>';
    } catch(e) {
        $('analyticsContent').innerHTML = `<div class="text-xs text-red-600">Failed to load analytics: ${e.message}</div>`;
    }
}

$('btnNewMeeting').addEventListener('click', () => {
    state.meetingId       = null;
    state.introChunkIndex = 0;
    state.participants    = [];
    $('stepIntro').classList.add('hidden');
    $('stepMeeting').classList.add('hidden');
    $('stepAnalytics').classList.add('hidden');
    $('introLog').textContent  = '';
    $('eventLog').textContent  = '';
    $('liveTranscript').innerHTML = '<div class="text-xs text-slate-400">Transcript will appear here…</div>';
    $('bars').innerHTML = '<div class="text-xs text-slate-400">Waiting for speech…</div>';
    $('elapsed').textContent = '00:00:00';
    $('crosstalkPct').textContent = '0';
    $('meetingIdLabel').textContent = '–';
    introUi('idle');
    state.introMode = 'http';
    $('btnIntroModeHttp').click();
    renderParticipants();
});

// ─────────────────────────── Boot ───────────────────────────
$('btnCheckApi').addEventListener('click', () => { savePrefs(); checkConnections(); });

async function openMeetingFromAppQuery() {
    const q = new URLSearchParams(window.location.search).get('meeting');
    if (!q || !/^\d+$/.test(q) || !state.token) return;
    const mid = parseInt(q, 10);
    try {
        const m = await api('/api/meetings/' + mid);
        state.meetingId = mid;
        $('meetingIdLabel').textContent = String(mid);
        $('meetingTitleLabel').textContent = m.title || 'Meeting';
        $('panelCreate')?.classList.add('hidden');
        $('stepIntro')?.classList.remove('hidden');
        await refreshParticipants();
        updateStartBtn();
        logIntro(`Opened meeting #${mid} from app`);
    } catch (e) {
        logIntro('Open from app failed: ' + e.message);
    }
}

loadPrefs();
introUi('idle');
void openMeetingFromAppQuery();
checkConnections();
</script>
@include('partials.pwa-register')
</body>
</html>
