<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>WeChirp · Meeting Demo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .pill { @apply inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium; }
        pre { white-space: pre-wrap; word-break: break-word; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">
<div class="max-w-5xl mx-auto p-4 space-y-4 pb-16">

    <!-- ── Header ── -->
    <div class="flex flex-wrap items-start justify-between gap-3 pt-2">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">WeChirp <span class="text-slate-400 font-normal">Meeting Demo</span></h1>
            <p class="text-sm text-slate-500 mt-0.5">Test backend: Auth → Intro enrollment → Live meeting → Analytics</p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs text-slate-500 items-start">
            <span id="badgeApi"    class="pill bg-slate-100 text-slate-500">API: –</span>
            <span id="badgeWs"     class="pill bg-slate-100 text-slate-500">WS: –</span>
            <span id="badgeDeepgram" class="pill bg-slate-100 text-slate-500">Deepgram: –</span>
        </div>
    </div>

    <!-- ── Config panel ── -->
    <div class="bg-white rounded-xl border p-4 space-y-3">
        <div class="font-semibold text-sm text-slate-700">Configuration</div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <label class="text-sm">
                <div class="text-slate-500 mb-1">Base URL</div>
                <input id="baseUrl" class="w-full border rounded px-3 py-1.5 font-mono text-xs" placeholder="http://127.0.0.1:8000" />
            </label>
            <label class="text-sm">
                <div class="text-slate-500 mb-1">Relay WS URL</div>
                <input id="relayWsUrl" class="w-full border rounded px-3 py-1.5 font-mono text-xs" placeholder="ws://127.0.0.1:8081" />
            </label>
            <label class="text-sm">
                <div class="text-slate-500 mb-1">Meeting title</div>
                <input id="meetingTitle" class="w-full border rounded px-3 py-1.5 text-xs" placeholder="Team Sync Q1" />
            </label>
        </div>
        <button id="btnCheckApi" class="px-3 py-1.5 rounded bg-slate-900 text-white text-xs font-medium">Check connections</button>
    </div>

    <!-- ── Auth panel ── -->
    <div class="bg-white rounded-xl border p-4 space-y-3">
        <div class="flex items-center justify-between">
            <div class="font-semibold text-sm text-slate-700">Authentication</div>
            <div id="authStatus" class="text-xs text-slate-500">Not logged in</div>
        </div>
        <div id="authForms" class="space-y-3">
            <div class="flex gap-2 border-b pb-3">
                <button id="tabLogin"  class="text-xs font-medium px-3 py-1 rounded bg-blue-600 text-white">Login</button>
                <button id="tabSignup" class="text-xs font-medium px-3 py-1 rounded bg-slate-100 text-slate-700">Signup</button>
            </div>
            <div id="formLogin" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <label class="text-sm">
                    <div class="text-slate-500 mb-1">Email</div>
                    <input id="loginEmail" type="email" class="w-full border rounded px-3 py-1.5 text-xs" placeholder="you@example.com" />
                </label>
                <label class="text-sm">
                    <div class="text-slate-500 mb-1">Password</div>
                    <input id="loginPassword" type="password" class="w-full border rounded px-3 py-1.5 text-xs" placeholder="••••••••" />
                </label>
                <div class="flex items-end">
                    <button id="btnLogin" class="px-4 py-1.5 rounded bg-blue-600 text-white text-xs font-medium w-full">Login</button>
                </div>
            </div>
            <div id="formSignup" class="hidden grid grid-cols-1 md:grid-cols-4 gap-3">
                <label class="text-sm">
                    <div class="text-slate-500 mb-1">Name</div>
                    <input id="signupName" class="w-full border rounded px-3 py-1.5 text-xs" placeholder="John Doe" />
                </label>
                <label class="text-sm">
                    <div class="text-slate-500 mb-1">Email</div>
                    <input id="signupEmail" type="email" class="w-full border rounded px-3 py-1.5 text-xs" />
                </label>
                <label class="text-sm">
                    <div class="text-slate-500 mb-1">Password</div>
                    <input id="signupPassword" type="password" class="w-full border rounded px-3 py-1.5 text-xs" />
                </label>
                <div class="flex items-end">
                    <button id="btnSignup" class="px-4 py-1.5 rounded bg-emerald-600 text-white text-xs font-medium w-full">Signup</button>
                </div>
            </div>
        </div>
        <div id="authLoggedIn" class="hidden flex items-center justify-between">
            <div class="text-sm">
                Logged in as <strong id="authUserName"></strong>
                <span class="text-xs text-slate-400 ml-2 font-mono" id="authTokenShort"></span>
            </div>
            <button id="btnLogout" class="text-xs text-red-600 underline">Logout</button>
        </div>
    </div>

    <!-- ── Step 0: Create meeting ── -->
    <div id="panelCreate" class="bg-white rounded-xl border p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex-1 min-w-0">
                <div class="font-semibold text-sm">Start a new meeting</div>
                <div class="text-xs text-slate-500 mt-0.5">Creates the meeting, then walks you through intro enrollment and live recording.</div>
            </div>
            <div class="flex items-center gap-2">
                <label class="flex items-center gap-2 text-xs text-slate-600 border rounded px-3 py-1.5">
                    <input id="useWs" type="checkbox" class="h-3.5 w-3.5" checked />
                    Use WebSocket
                </label>
                <button id="btnCreateMeeting" class="px-4 py-2 rounded bg-blue-600 text-white text-sm font-semibold disabled:opacity-40" disabled>Create meeting</button>
            </div>
        </div>
    </div>

    <!-- ── Step 1: Intro enrollment ── -->
    <div id="stepIntro" class="hidden bg-white rounded-xl border p-5 space-y-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <div class="text-xs text-slate-400 font-medium uppercase tracking-wide">Step 1 of 2</div>
                <h2 class="text-lg font-semibold mt-0.5">Participant Introductions</h2>
                <p class="text-sm text-slate-500">Each participant says: <span class="font-semibold text-slate-800">"My name is [name]"</span> clearly into the mic.</p>
            </div>
            <div class="text-right space-y-1">
                <div class="text-xs text-slate-500">Meeting ID</div>
                <div id="meetingIdLabel" class="font-mono text-sm font-bold text-blue-700">–</div>
            </div>
        </div>

        <!-- Intro mode switch -->
        <div class="flex gap-2">
            <button id="btnIntroModeHttp" class="px-3 py-1.5 rounded text-xs font-medium bg-blue-600 text-white">HTTP chunks (Deepgram)</button>
            <button id="btnIntroModeWs"   class="px-3 py-1.5 rounded text-xs font-medium bg-slate-100 text-slate-700">Via WebSocket (live)</button>
        </div>

        <!-- HTTP intro panel -->
        <div id="introHttp" class="space-y-3">
            <div class="flex flex-wrap items-center gap-3">
                <button id="btnIntroEnroll" class="px-4 py-2 rounded bg-slate-900 text-white text-sm font-semibold">● Record intro</button>
                <button id="btnIntroNext"   class="px-4 py-2 rounded bg-emerald-50 border border-emerald-300 text-emerald-800 text-sm font-semibold hidden">+ Add another</button>
                <button id="btnIntroRetry"  class="px-4 py-2 rounded bg-amber-50 border border-amber-300 text-amber-800 text-sm font-semibold hidden">↺ Try again</button>
                <label class="text-xs text-slate-500 flex items-center gap-2">
                    Duration (s)
                    <input id="introSeconds" class="w-16 border rounded px-2 py-1 font-mono text-xs" value="6" />
                </label>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg px-3 py-2 text-xs text-blue-800">
                Audio is sent to <strong>Deepgram</strong> for speaker-labeled transcription → name extracted → stored in DB.
            </div>
        </div>

        <!-- WS intro panel -->
        <div id="introWs" class="hidden space-y-2">
            <div class="bg-purple-50 border border-purple-200 rounded-lg px-3 py-2 text-xs text-purple-800 space-y-1">
                <div class="font-semibold">WebSocket intro mode — important</div>
                <div>Click <em>Start meeting</em>, then <strong>each participant must say "My name is [name]"</strong> clearly into the mic at the beginning of the session. Deepgram will assign speaker labels and map them to names automatically.</div>
                <div class="text-purple-600">If you already enrolled via HTTP chunks above, say the same name again in the live session — the system will merge them automatically.</div>
            </div>
        </div>

        <!-- Enrolled participants -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="border rounded-xl p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="font-semibold text-sm">Enrolled participants</div>
                    <button id="btnRefreshParticipants" class="text-xs text-slate-500 underline">Refresh</button>
                </div>
                <div id="participantList" class="space-y-2">
                    <div class="text-xs text-slate-400">No participants yet.</div>
                </div>
            </div>
            <div class="border rounded-xl p-4 space-y-2">
                <div class="font-semibold text-sm">Intro log</div>
                <pre id="introLog" class="bg-slate-950 text-slate-100 rounded p-3 text-xs h-44 overflow-auto"></pre>
            </div>
        </div>

        <div class="flex items-center justify-between pt-1">
            <label class="text-xs text-slate-500 flex items-center gap-2">
                Meeting chunk (ms)
                <input id="meetingChunkMs" class="w-20 border rounded px-2 py-1 font-mono text-xs" value="5000" />
            </label>
            <button id="btnStartMeeting" class="px-5 py-2.5 rounded bg-emerald-600 text-white text-sm font-bold disabled:opacity-40" disabled>Start meeting →</button>
        </div>
    </div>

    <!-- ── Step 2: Live meeting ── -->
    <div id="stepMeeting" class="hidden space-y-4">

        <!-- Meeting header -->
        <div class="bg-white rounded-xl border p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="meetingTitleLabel" class="text-lg font-semibold">Meeting</h2>
                    <div class="text-3xl font-bold tabular-nums mt-1" id="elapsed">00:00:00</div>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <div id="statusPill" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-medium">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span id="statusText">Recording</span>
                    </div>
                    <div id="wsStatusBadge" class="hidden text-xs text-slate-400 font-mono"></div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 mt-4">
                <button id="btnPause"  class="px-4 py-2 rounded bg-slate-100 border text-sm font-medium">⏸ Pause</button>
                <button id="btnResume" class="px-4 py-2 rounded bg-slate-900 text-white text-sm font-medium hidden">▶ Resume</button>
                <button id="btnEnd"    class="ml-auto px-4 py-2 rounded bg-red-600 text-white text-sm font-semibold">■ End meeting</button>
            </div>
        </div>

        <!-- Speaker stats -->
        <div class="bg-white rounded-xl border p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div class="font-semibold">Speaker talk time</div>
                <div class="text-xs text-slate-500">Crosstalk: <strong><span id="crosstalkPct">0</span>%</strong></div>
            </div>
            <div id="bars" class="space-y-3">
                <div class="text-xs text-slate-400">Waiting for speech…</div>
            </div>
        </div>

        <!-- Live transcript -->
        <div class="bg-white rounded-xl border p-5 space-y-3">
            <div class="flex items-center justify-between">
                <div class="font-semibold">Live transcript</div>
                <span id="transcriptMode" class="text-xs text-slate-400"></span>
            </div>
            <div id="liveTranscript" class="space-y-2 min-h-[80px]">
                <div class="text-xs text-slate-400">Transcript will appear here…</div>
            </div>
        </div>

        <!-- Event log -->
        <div class="bg-white rounded-xl border p-5 space-y-2">
            <div class="font-semibold text-sm">Event log</div>
            <pre id="eventLog" class="bg-slate-950 text-slate-100 rounded p-3 text-xs h-40 overflow-auto"></pre>
        </div>
    </div>

    <!-- ── Step 3: Analytics ── -->
    <div id="stepAnalytics" class="hidden bg-white rounded-xl border p-5 space-y-4">
        <div>
            <div class="text-xs text-slate-400 font-medium uppercase tracking-wide">Meeting ended</div>
            <h2 class="text-lg font-semibold mt-0.5">Final Analytics</h2>
        </div>
        <div id="analyticsContent" class="space-y-4">
            <div class="text-xs text-slate-400">Loading…</div>
        </div>
        <button id="btnNewMeeting" class="px-4 py-2 rounded bg-blue-600 text-white text-sm font-semibold">+ New meeting</button>
    </div>

</div><!-- /container -->

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
    },
    ws: { socket: null, connected: false },
    sse: null,
};

// ─────────────────────────── Persist ───────────────────────────
function loadPrefs() {
    $('baseUrl').value       = localStorage.getItem('wc.baseUrl')       || 'http://127.0.0.1:8000';
    $('relayWsUrl').value    = localStorage.getItem('wc.relayWsUrl')    || 'ws://127.0.0.1:8081';
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
        badge('badgeWs', r.ok, 'WS relay');
    } catch { badge('badgeWs', false, 'WS relay'); }

    badge('badgeDeepgram', true, 'Deepgram');
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
    $('tabLogin').className  = 'text-xs font-medium px-3 py-1 rounded bg-blue-600 text-white';
    $('tabSignup').className = 'text-xs font-medium px-3 py-1 rounded bg-slate-100 text-slate-700';
});
$('tabSignup').addEventListener('click', () => {
    $('formSignup').classList.remove('hidden');
    $('formLogin').classList.add('hidden');
    $('tabSignup').className = 'text-xs font-medium px-3 py-1 rounded bg-blue-600 text-white';
    $('tabLogin').className  = 'text-xs font-medium px-3 py-1 rounded bg-slate-100 text-slate-700';
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
    $('authStatus').textContent = 'Not logged in';
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
    $('authStatus').textContent = 'Authenticated ✓';
    $('btnCreateMeeting').disabled = false;
}

// ─────────────────────────── Intro mode toggle ───────────────────────────
$('btnIntroModeHttp').addEventListener('click', () => {
    state.introMode = 'http';
    $('introHttp').classList.remove('hidden');
    $('introWs').classList.add('hidden');
    $('btnIntroModeHttp').className = 'px-3 py-1.5 rounded text-xs font-medium bg-blue-600 text-white';
    $('btnIntroModeWs').className   = 'px-3 py-1.5 rounded text-xs font-medium bg-slate-100 text-slate-700';
    updateStartBtn();
});
$('btnIntroModeWs').addEventListener('click', () => {
    state.introMode = 'ws';
    $('introWs').classList.remove('hidden');
    $('introHttp').classList.add('hidden');
    $('btnIntroModeWs').className   = 'px-3 py-1.5 rounded text-xs font-medium bg-purple-600 text-white';
    $('btnIntroModeHttp').className = 'px-3 py-1.5 rounded text-xs font-medium bg-slate-100 text-slate-700';
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
        wrap.innerHTML = '<div class="text-xs text-slate-400">No participants enrolled yet.</div>';
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
        el.className = 'border rounded-lg px-3 py-2.5 space-y-1.5';
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

    const seconds = Math.max(3, Math.min(15, parseInt($('introSeconds').value, 10) || 6));
    logIntro(`▶ Recording ${seconds}s — say: "My name is [name]"`);

    const stream  = await navigator.mediaDevices.getUserMedia({ audio: true });
    const mime    = MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')
        ? 'audio/ogg;codecs=opus'
        : MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
            ? 'audio/webm;codecs=opus' : 'audio/webm';

    const beforeNames = new Set(state.participants.filter(p => !isPlaceholder(p.name)).map(p => p.name.toLowerCase()));

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
    fd.append('audio', blob, `intro.${ext}`);

    const res = await api(`/api/meetings/${state.meetingId}/intro/chunk?sync=1`, { method: 'POST', body: fd });
    logIntro(`✓ Server responded: status=${res?.status}`);

    if (res?.status === 'failed') throw new Error(String(res.error || 'analyzer_failed'));

    await refreshParticipants();
    const newNames = state.participants.filter(p => !isPlaceholder(p.name) && !beforeNames.has(p.name.toLowerCase()));

    state.introEnrolling = false;
    if (newNames.length > 0) {
        state.introWaitingNext = true;
        logIntro(`✅ Enrolled: ${newNames.map(p => p.name).join(', ')}`);
        introUi('done');
    } else {
        logIntro('⚠ No name detected — please try again, say your name clearly.');
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
            startWs();
            $('transcriptMode').textContent = 'Live (WebSocket → Deepgram)';
        } else {
            startHttpChunks();
            startSse();
            $('transcriptMode').textContent = 'Polling (HTTP chunks)';
        }
    } catch(e) { alert('Start meeting failed: ' + e.message); }
});

// ─────────────────────────── WebSocket live ───────────────────────────
function startWs() {
    const token  = state.token;
    const base   = $('relayWsUrl').value.trim().replace(/\/$/, '');
    const wsUrl  = `${base}/meetings/${state.meetingId}/live?token=${encodeURIComponent(token)}`;
    const ws     = new WebSocket(wsUrl);
    state.ws.socket = ws;

    $('wsStatusBadge').classList.remove('hidden');
    $('wsStatusBadge').textContent = 'WS: connecting…';

    ws.onopen = () => {
        state.ws.connected = true;
        logEvent('WS connected ✓');
        $('wsStatusBadge').textContent = 'WS: connected ✓';
    };
    ws.onerror = () => { logEvent('WS error'); $('wsStatusBadge').textContent = 'WS: error ✗'; };
    ws.onclose = () => {
        state.ws.connected = false;
        logEvent('WS closed');
        $('wsStatusBadge').textContent = 'WS: closed';
    };
    ws.onmessage = ev => {
        try {
            const msg = JSON.parse(String(ev.data || ''));
            if (msg?.error) { logEvent(`WS error: ${msg.error}${msg.message ? ' – ' + msg.message : ''}`); return; }
            if (msg?.event === 'stats.updated')      renderBars(msg.data);
            if (msg?.event === 'transcript.updated') renderLiveLines(msg.data?.lines || []);
        } catch {}
    };

    // Stream mic audio
    navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
        state.meeting.stream = stream;
        const mime = MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')
            ? 'audio/ogg;codecs=opus'
            : MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                ? 'audio/webm;codecs=opus' : 'audio/webm';

        const recorder = new MediaRecorder(stream, { mimeType: mime });
        state.meeting.recorder = recorder;
        recorder.ondataavailable = async ev => {
            if (!state.meeting.running || state.meeting.paused) return;
            if (!ev.data || ev.data.size === 0) return;
            if (ws.readyState !== WebSocket.OPEN) return;
            try { ws.send(await ev.data.arrayBuffer()); } catch {}
        };
        recorder.start(250);
        logEvent(`Mic streaming started (${mime}) via WebSocket`);

        if (state.introMode === 'ws') {
            logEvent('🎤 Intro mode: each participant say "My name is [name]" now, then continue normally.');
        }
    }).catch(e => logEvent('Mic error: ' + e.message));
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
    bars.innerHTML = '';
    const sorted = [...parts].sort((a, b) => (b.talk_percentage || 0) - (a.talk_percentage || 0));
    sorted.forEach(p => {
        const pct = Math.max(0, Math.min(100, Number(p.talk_percentage || 0)));
        const el  = document.createElement('div');
        el.className = 'space-y-1';
        el.innerHTML = `
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium">${p.name}</span>
                <span class="text-slate-500 tabular-nums">${pct.toFixed(0)}% · ${p.talk_time ?? 0}s</span>
            </div>
            <div class="h-2.5 rounded-full bg-slate-100 overflow-hidden">
                <div class="h-2.5 rounded-full bg-blue-500 transition-all" style="width:${pct}%"></div>
            </div>
        `;
        bars.appendChild(el);
    });
    $('crosstalkPct').textContent = String(Math.round(payload?.crosstalk_percentage || 0));
}

function renderLiveLines(lines) {
    const wrap = $('liveTranscript');
    if (!Array.isArray(lines) || lines.length === 0) return;
    wrap.innerHTML = '';
    lines.filter(x => String(x.text || '').trim()).forEach(x => {
        const el = document.createElement('div');
        el.className = 'flex gap-2 text-sm';
        const nameColor = isPlaceholder(x.name || x.label)
            ? 'text-slate-400' : 'text-blue-700 font-semibold';
        el.innerHTML = `
            <span class="${nameColor} whitespace-nowrap min-w-[80px] text-right">${x.name || x.label}</span>
            <span class="text-slate-700">${x.text}</span>
        `;
        wrap.appendChild(el);
    });
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
    try { await api(`/api/meetings/${state.meetingId}/end`, { method: 'POST' }); } catch {}

    // Give relay 1.5s to flush final stats then show analytics
    await sleep(1500);
    showAnalytics();
});

function stopMic() {
    state.meeting.running = false;
    try { state.meeting.recorder?.stop(); } catch {}
    try { state.meeting.stream?.getTracks().forEach(t => t.stop()); } catch {}
    try { state.ws.socket?.close(); } catch {}
    state.ws.socket    = null;
    state.ws.connected = false;
    state.meeting.recorder = null;
    state.meeting.stream   = null;
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

loadPrefs();
checkConnections();
introUi('idle');
</script>
</body>
</html>
