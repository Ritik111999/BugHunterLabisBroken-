<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>WeChirp API Tester</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="max-w-6xl mx-auto p-6 space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">WeChirp API Tester</h1>
            <p class="text-sm text-slate-600">Test login → meeting → audio → stats → transcripts → mapping.</p>
        </div>
        <div class="text-right text-xs text-slate-600">
            <div>Base URL: <span id="baseUrlLabel" class="font-mono"></span></div>
            <div>Token: <span id="tokenLabel" class="font-mono"></span></div>
            <div>Meeting: <span id="meetingLabel" class="font-mono"></span></div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl border p-4 space-y-4">
            <h2 class="font-semibold">Auth</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Base URL</div>
                    <input id="baseUrl" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="http://127.0.0.1:8000" />
                </label>
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Token (optional)</div>
                    <input id="token" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="paste bearer token" />
                </label>
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Email</div>
                    <input id="email" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="rajat@example.com" />
                </label>
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Password</div>
                    <input id="password" type="password" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="password" />
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <button id="btnLogin" class="px-3 py-2 rounded bg-slate-900 text-white text-sm">Login</button>
                <button id="btnLogout" class="px-3 py-2 rounded bg-slate-100 text-slate-900 text-sm border">Clear token</button>
            </div>
        </div>

        <div class="bg-white rounded-xl border p-4 space-y-4">
            <h2 class="font-semibold">Meeting</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Meeting title</div>
                    <input id="meetingTitle" class="w-full border rounded px-3 py-2 text-sm" placeholder="Demo Meeting" />
                </label>
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Meeting ID</div>
                    <input id="meetingId" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="(auto)" />
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <button id="btnCreateMeeting" class="px-3 py-2 rounded bg-blue-600 text-white text-sm">Create</button>
                <button id="btnStartMeeting" class="px-3 py-2 rounded bg-blue-50 text-blue-700 text-sm border">Start</button>
                <button id="btnEndMeeting" class="px-3 py-2 rounded bg-blue-50 text-blue-700 text-sm border">End</button>
                <button id="btnFetchStats" class="px-3 py-2 rounded bg-emerald-600 text-white text-sm">Fetch stats</button>
                <button id="btnFetchTranscripts" class="px-3 py-2 rounded bg-emerald-50 text-emerald-700 text-sm border">Fetch transcripts</button>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white rounded-xl border p-4 space-y-4 lg:col-span-1">
            <h2 class="font-semibold">Upload audio chunk (HTTP)</h2>
            <div class="grid grid-cols-1 gap-3">
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Chunk index</div>
                    <input id="chunkIndex" class="w-full border rounded px-3 py-2 font-mono text-sm" value="0" />
                </label>
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Audio file</div>
                    <input id="chunkFile" type="file" class="w-full border rounded px-3 py-2 text-sm" />
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <button id="btnSendChunk" class="px-3 py-2 rounded bg-purple-600 text-white text-sm">Send chunk</button>
            </div>
            <p class="text-xs text-slate-600">
                Tip: For intro enrollment say “my name is Rajat”. Then check transcripts.
            </p>
        </div>

        <div class="bg-white rounded-xl border p-4 space-y-4 lg:col-span-1">
            <h2 class="font-semibold">Live mic → chunks → processing</h2>
            <div class="grid grid-cols-1 gap-3">
                <div class="text-xs text-slate-600">
                    Records mic using <span class="font-mono">MediaRecorder</span> and uploads chunks to <span class="font-mono">/api/audio/chunk</span>.
                    Best for testing the exact chunk pipeline.
                </div>
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Chunk duration (ms)</div>
                    <input id="micChunkMs" class="w-full border rounded px-3 py-2 font-mono text-sm" value="3000" />
                </label>
                <div class="flex gap-2">
                    <button id="btnMicStart" class="px-3 py-2 rounded bg-slate-900 text-white text-sm">Start mic</button>
                    <button id="btnMicStop" class="px-3 py-2 rounded bg-slate-100 text-slate-900 text-sm border">Stop</button>
                </div>
                <div class="text-xs text-slate-600">Mic log</div>
                <pre id="micLog" class="bg-slate-950 text-slate-100 rounded p-3 text-xs overflow-auto h-40"></pre>
            </div>
        </div>

        <div class="bg-white rounded-xl border p-4 space-y-4 lg:col-span-1">
            <h2 class="font-semibold">Realtime stats (SSE)</h2>
            <div class="flex gap-2">
                <button id="btnSseStart" class="px-3 py-2 rounded bg-slate-900 text-white text-sm">Start SSE</button>
                <button id="btnSseStop" class="px-3 py-2 rounded bg-slate-100 text-slate-900 text-sm border">Stop</button>
            </div>
            <div class="text-xs text-slate-600">Events</div>
            <pre id="sseLog" class="bg-slate-950 text-slate-100 rounded p-3 text-xs overflow-auto h-56"></pre>
        </div>

        <div class="bg-white rounded-xl border p-4 space-y-4 lg:col-span-1">
            <h2 class="font-semibold">Speaker mapping</h2>
            <div class="grid grid-cols-1 gap-3">
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Speaker label</div>
                    <input id="speakerLabel" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="speaker_0" />
                </label>
                <label class="text-sm">
                    <div class="text-slate-600 mb-1">Name</div>
                    <input id="speakerName" class="w-full border rounded px-3 py-2 text-sm" placeholder="Rajat" />
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <button id="btnListSpeakers" class="px-3 py-2 rounded bg-slate-100 text-slate-900 text-sm border">List mappings</button>
                <button id="btnMapSpeaker" class="px-3 py-2 rounded bg-slate-900 text-white text-sm">Map</button>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl border p-4 space-y-3">
            <h2 class="font-semibold">Stats (DB)</h2>
            <pre id="statsOut" class="bg-slate-950 text-slate-100 rounded p-3 text-xs overflow-auto h-80"></pre>
        </div>
        <div class="bg-white rounded-xl border p-4 space-y-3">
            <h2 class="font-semibold">Transcripts (DB)</h2>
            <pre id="transcriptsOut" class="bg-slate-950 text-slate-100 rounded p-3 text-xs overflow-auto h-80"></pre>
        </div>
    </div>

    <div class="bg-white rounded-xl border p-4 space-y-3">
        <h2 class="font-semibold">Raw API output</h2>
        <pre id="rawOut" class="bg-slate-950 text-slate-100 rounded p-3 text-xs overflow-auto h-64"></pre>
    </div>
</div>

<script>
    const $ = (id) => document.getElementById(id);

    const state = {
        sse: null,
        mic: {
            stream: null,
            recorder: null,
            chunkIndex: 0,
            running: false,
        },
    };

    function loadDefaults() {
        const baseUrl = localStorage.getItem("tester.baseUrl") || "http://127.0.0.1:8000";
        const token = localStorage.getItem("tester.token") || "";
        const meetingId = localStorage.getItem("tester.meetingId") || "";
        $("baseUrl").value = baseUrl;
        $("token").value = token;
        $("meetingId").value = meetingId;
        renderLabels();
    }

    function saveDefaults() {
        localStorage.setItem("tester.baseUrl", $("baseUrl").value.trim());
        localStorage.setItem("tester.token", $("token").value.trim());
        localStorage.setItem("tester.meetingId", $("meetingId").value.trim());
        renderLabels();
    }

    function renderLabels() {
        $("baseUrlLabel").textContent = $("baseUrl").value.trim();
        const t = $("token").value.trim();
        $("tokenLabel").textContent = t ? (t.slice(0, 10) + "…" + t.slice(-6)) : "(none)";
        $("meetingLabel").textContent = $("meetingId").value.trim() || "(none)";
    }

    async function api(path, { method = "GET", headers = {}, body } = {}) {
        const baseUrl = $("baseUrl").value.trim();
        const token = $("token").value.trim();

        const h = { ...headers };
        if (token) h["Authorization"] = `Bearer ${token}`;

        const res = await fetch(baseUrl + path, { method, headers: h, body });
        const text = await res.text();
        let json = null;
        try { json = JSON.parse(text); } catch {}

        $("rawOut").textContent = JSON.stringify({
            request: { method, path, headers: h },
            status: res.status,
            body: json ?? text
        }, null, 2);

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }
        return json ?? text;
    }

    function prettySet(el, obj) {
        el.textContent = typeof obj === "string" ? obj : JSON.stringify(obj, null, 2);
    }

    $("baseUrl").addEventListener("change", saveDefaults);
    $("token").addEventListener("change", saveDefaults);
    $("meetingId").addEventListener("change", saveDefaults);

    $("btnLogin").addEventListener("click", async () => {
        try {
            const email = $("email").value.trim();
            const password = $("password").value;
            const out = await api("/api/login", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ email, password })
            });
            if (out?.token) {
                $("token").value = out.token;
                saveDefaults();
            }
        } catch (e) {
            alert("Login failed. Check email/password.");
        }
    });

    $("btnLogout").addEventListener("click", () => {
        $("token").value = "";
        saveDefaults();
    });

    $("btnCreateMeeting").addEventListener("click", async () => {
        try {
            const title = $("meetingTitle").value.trim();
            const out = await api("/api/meetings", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ title })
            });
            if (out?.id) {
                $("meetingId").value = String(out.id);
                saveDefaults();
            }
        } catch (e) {
            alert("Create meeting failed (need auth token).");
        }
    });

    $("btnStartMeeting").addEventListener("click", async () => {
        try {
            const id = $("meetingId").value.trim();
            await api(`/api/meetings/${id}/start`, { method: "POST" });
        } catch (e) {
            alert("Start meeting failed.");
        }
    });

    $("btnEndMeeting").addEventListener("click", async () => {
        try {
            const id = $("meetingId").value.trim();
            await api(`/api/meetings/${id}/end`, { method: "POST" });
        } catch (e) {
            alert("End meeting failed.");
        }
    });

    $("btnSendChunk").addEventListener("click", async () => {
        try {
            const id = $("meetingId").value.trim();
            const idx = $("chunkIndex").value.trim();
            const file = $("chunkFile").files?.[0];
            if (!id) return alert("Set meetingId");
            if (!file) return alert("Choose an audio file");

            const fd = new FormData();
            fd.append("meeting_id", id);
            fd.append("chunk_index", idx);
            fd.append("audio", file);
            await api("/api/audio/chunk", { method: "POST", body: fd });
        } catch (e) {
            alert("Send chunk failed. Check server + queue worker.");
        }
    });

    function micLog(line) {
        const t = `[${new Date().toLocaleTimeString()}] ${line}`;
        $("micLog").textContent = (t + "\n") + $("micLog").textContent;
    }

    async function uploadBlobAsChunk(blob) {
        const id = $("meetingId").value.trim();
        if (!id) throw new Error("missing meetingId");
        const fd = new FormData();
        fd.append("meeting_id", id);
        fd.append("chunk_index", String(state.mic.chunkIndex++));
        const ext = (String(blob?.type || "").includes("ogg") ? "ogg" : "webm");
        fd.append("audio", blob, `chunk_${Date.now()}.${ext}`);
        // sync=1 => process immediately so transcript appears while speaking
        await api("/api/audio/chunk?sync=1", { method: "POST", body: fd });
    }

    $("btnMicStart").addEventListener("click", async () => {
        try {
            const token = $("token").value.trim();
            if (!token) return alert("Login first (token required)");
            const id = $("meetingId").value.trim();
            if (!id) return alert("Create meeting first");

            const chunkMs = Math.max(1000, parseInt($("micChunkMs").value, 10) || 3000);
            state.mic.chunkIndex = 0;
            state.mic.running = true;
            $("micLog").textContent = "";

            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            state.mic.stream = stream;

            const mime = MediaRecorder.isTypeSupported("audio/ogg;codecs=opus")
                ? "audio/ogg;codecs=opus"
                : (MediaRecorder.isTypeSupported("audio/webm;codecs=opus") ? "audio/webm;codecs=opus" : "audio/webm");

            const recorder = new MediaRecorder(stream, { mimeType: mime });
            state.mic.recorder = recorder;

            recorder.ondataavailable = async (ev) => {
                if (!state.mic.running) return;
                if (!ev.data || ev.data.size === 0) return;
                try {
                    micLog(`uploading chunk #${state.mic.chunkIndex} (${ev.data.size} bytes, ${mime})`);
                    await uploadBlobAsChunk(ev.data);
                    micLog(`uploaded chunk #${state.mic.chunkIndex - 1}`);

                    // Auto-refresh stats + transcripts after each chunk
                    const meetingId = $("meetingId").value.trim();
                    const stats = await api(`/api/meetings/${meetingId}/stats`);
                    prettySet($("statsOut"), stats);
                    const transcripts = await api(`/api/meetings/${meetingId}/transcripts`);
                    prettySet($("transcriptsOut"), transcripts);
                } catch (e) {
                    micLog(`upload failed: ${e?.message ?? e}`);
                }
            };

            recorder.start(chunkMs);
            micLog(`mic started (${mime}), chunk every ${chunkMs}ms`);
        } catch (e) {
            alert("Mic start failed (check browser permissions).");
        }
    });

    $("btnMicStop").addEventListener("click", () => {
        state.mic.running = false;
        try { state.mic.recorder?.stop(); } catch {}
        try { state.mic.stream?.getTracks()?.forEach(t => t.stop()); } catch {}
        state.mic.recorder = null;
        state.mic.stream = null;
        micLog("mic stopped");
    });

    $("btnFetchStats").addEventListener("click", async () => {
        try {
            const id = $("meetingId").value.trim();
            const out = await api(`/api/meetings/${id}/stats`);
            prettySet($("statsOut"), out);
        } catch (e) {
            alert("Fetch stats failed.");
        }
    });

    $("btnFetchTranscripts").addEventListener("click", async () => {
        try {
            const id = $("meetingId").value.trim();
            const out = await api(`/api/meetings/${id}/transcripts`);
            prettySet($("transcriptsOut"), out);
        } catch (e) {
            alert("Fetch transcripts failed.");
        }
    });

    $("btnListSpeakers").addEventListener("click", async () => {
        try {
            const id = $("meetingId").value.trim();
            const out = await api(`/api/meetings/${id}/speakers`);
            prettySet($("rawOut"), out);
        } catch (e) {
            alert("List speakers failed.");
        }
    });

    $("btnMapSpeaker").addEventListener("click", async () => {
        try {
            const id = $("meetingId").value.trim();
            const speaker_label = $("speakerLabel").value.trim();
            const name = $("speakerName").value.trim();
            const out = await api(`/api/meetings/${id}/speakers/map`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ speaker_label, name })
            });
            prettySet($("rawOut"), out);
        } catch (e) {
            alert("Map speaker failed.");
        }
    });

    $("btnSseStart").addEventListener("click", () => {
        const id = $("meetingId").value.trim();
        const baseUrl = $("baseUrl").value.trim();
        const token = $("token").value.trim();
        if (!id) return alert("Set meetingId");
        if (!token) return alert("Login first (token required)");

        if (state.sse) state.sse.close();

        // SSE doesn't support Authorization header in EventSource, so we pass token as query param.
        // Your API is protected; for local testing only. For production, use cookies or a proxy.
        const url = `${baseUrl}/api/meetings/${id}/stats/stream?token=${encodeURIComponent(token)}`;

        $("sseLog").textContent = "";
        state.sse = new EventSource(url);
        state.sse.addEventListener("stats.updated", (ev) => {
            $("sseLog").textContent = (ev.data + "\n\n") + $("sseLog").textContent;
        });
        state.sse.onerror = () => {
            $("sseLog").textContent = "[SSE error]\n" + $("sseLog").textContent;
        };
    });

    $("btnSseStop").addEventListener("click", () => {
        if (state.sse) state.sse.close();
        state.sse = null;
    });

    loadDefaults();
</script>
</body>
</html>

