import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { api, getToken } from '../api.js';
import MeetingSummary from '../components/MeetingSummary.jsx';
import RecordingHealth from '../components/RecordingHealth.jsx';
import TranscriptFeed from '../components/TranscriptFeed.jsx';
import StudioLiveLogPanel from '../components/StudioLiveLogPanel.jsx';
import StudioVoiceDebugPanel from '../components/StudioVoiceDebugPanel.jsx';
import { isCapacitorIos, isConsumerMode, isLikelyIosSimulator, showDevTools, showStudioDebugPanels } from '../otter/helpers.js';
import { hasEcapaVoiceprintEmbedding, isPlaceholder } from '../studio/helpers.js';
import { MeetingLiveSession } from '../studio/MeetingLiveSession.js';
import { fetchMeetingServicesHealth, healthSummary } from '../studio/meetingServices.js';

function hasDeviceSpeechRecognition() {
    return typeof globalThis !== 'undefined' && !!(globalThis.SpeechRecognition || globalThis.webkitSpeechRecognition);
}

function readLs(key, fallback) {
    try {
        const v = localStorage.getItem(key);
        return v != null && v !== '' ? v : fallback;
    } catch {
        return fallback;
    }
}

export default function MeetingStudio() {
    const { id } = useParams();
    const [searchParams] = useSearchParams();
    const autoLive = searchParams.get('live') === '1';
    const consumer = isConsumerMode();
    const dev = showDevTools();
    const studioDebug = showStudioDebugPanels();
    const sessionRef = useRef(null);
    const autoLiveStartedRef = useRef(false);
    const settingsRef = useRef({
        relayWsUrl: '',
        useWs: true,
        introSeconds: '6',
        chunkMs: '5000',
        micProfile: 'default',
    });

    const [meetingTitle, setMeetingTitle] = useState('');
    const [phase, setPhase] = useState('intro');
    const [introUi, setIntroUi] = useState('idle');
    const [introMode, setIntroMode] = useState('http');
    const [introHeard, setIntroHeard] = useState([]);
    const [introLog, setIntroLog] = useState([]);
    const [introMicLevel, setIntroMicLevel] = useState({ pct: 0, peak: 0 });
    const [eventLog, setEventLog] = useState([]);
    const [participants, setParticipants] = useState([]);
    const [startEnabled, setStartEnabled] = useState(false);
    const [elapsed, setElapsed] = useState('0:00');
    const [transcriptMode, setTranscriptMode] = useState('');
    const [transcriptLines, setTranscriptLines] = useState([]);
    const [bars, setBars] = useState([]);
    const [crosstalk, setCrosstalk] = useState(0);
    const [wsStatus, setWsStatus] = useState('');
    const [transport, setTransport] = useState({ mode: '', label: '' });
    const [micHint, setMicHint] = useState('');
    const [voiceDebugData, setVoiceDebugData] = useState(null);
    const [liveLog, setLiveLog] = useState([]);
    const [paused, setPaused] = useState(false);
    const [analytics, setAnalytics] = useState(null);
    const [err, setErr] = useState('');
    const [busy, setBusy] = useState(false);
    const [ending, setEnding] = useState(false);
    const autoLiveRef = useRef(autoLive);
    const consumerRef = useRef(consumer);
    autoLiveRef.current = autoLive;
    consumerRef.current = consumer;
    const [servicesHealth, setServicesHealth] = useState(() => {
        const b = typeof window !== 'undefined' ? window.__WECHIRP_BOOT__ : null;
        if (!b || typeof b !== 'object') return null;
        return {
            stt_configured: !!b.sttConfigured,
            voiceprint_verified: !!b.voiceprintReady,
            voiceprint_venv_ready: !!b.voiceprintReady,
        };
    });
    const servicesSummary = useMemo(() => healthSummary(servicesHealth), [servicesHealth]);

    const [introBindParticipantId, setIntroBindParticipantId] = useState(null);
    const introBindParticipantIdRef = useRef(null);

    const iosSimulatorMicTip = useMemo(() => isLikelyIosSimulator(), []);

    const [relayWsUrl, setRelayWsUrl] = useState(() =>
        readLs(
            'wc.relayWsUrl',
            (typeof window !== 'undefined' && window.__WECHIRP_BOOT__?.relayWsUrl) || 'ws://127.0.0.1:9001',
        ),
    );
    const [useWs, setUseWs] = useState(() => (readLs('wc.useWs', '1') ?? '1') === '1');
    const [introSeconds, setIntroSeconds] = useState(() => readLs('wc.introSeconds', '6'));
    const [chunkMs, setChunkMs] = useState(() => readLs('wc.chunkMs', '5000'));
    const [micProfile, setMicProfile] = useState(() => readLs('wc.micProfile', 'default'));

    useEffect(() => {
        try {
            localStorage.setItem('wc.relayWsUrl', relayWsUrl.trim());
        } catch {}
    }, [relayWsUrl]);
    useEffect(() => {
        try {
            localStorage.setItem('wc.useWs', useWs ? '1' : '0');
        } catch {}
    }, [useWs]);
    useEffect(() => {
        try {
            localStorage.setItem('wc.introSeconds', introSeconds.trim());
        } catch {}
    }, [introSeconds]);
    useEffect(() => {
        try {
            localStorage.setItem('wc.chunkMs', chunkMs.trim());
        } catch {}
    }, [chunkMs]);
    useEffect(() => {
        try {
            localStorage.setItem('wc.micProfile', micProfile.trim());
        } catch {}
    }, [micProfile]);

    settingsRef.current = { relayWsUrl, useWs, introSeconds, chunkMs, micProfile };

    const emit = useCallback((type, data) => {
        switch (type) {
            case 'phase':
                setPhase(data.phase);
                if (data.phase === 'live') {
                    setAnalytics(null);
                }
                break;
            case 'introUi':
                setIntroUi(data.mode);
                break;
            case 'introMode':
                setIntroMode(data.mode);
                break;
            case 'introHeard':
                setIntroHeard(Array.isArray(data.segments) ? data.segments : []);
                break;
            case 'introLog':
                setIntroLog((prev) => [data.line, ...prev].slice(0, 120));
                if (studioDebug) setLiveLog((prev) => [data.line, ...prev].slice(0, 200));
                break;
            case 'introMicLevel':
                setIntroMicLevel({
                    pct: typeof data.pct === 'number' ? data.pct : 0,
                    peak: typeof data.peak === 'number' ? data.peak : 0,
                });
                break;
            case 'eventLog':
                setEventLog((prev) => [data.line, ...prev].slice(0, 160));
                if (studioDebug) setLiveLog((prev) => [data.line, ...prev].slice(0, 200));
                break;
            case 'participants':
                setParticipants(data.list || []);
                break;
            case 'startEnabled':
                setStartEnabled(!!data.enabled);
                break;
            case 'elapsed':
                setElapsed(data.text || '');
                break;
            case 'transcriptMode':
                setTranscriptMode(data.text || '');
                break;
            case 'transcriptClear':
                setTranscriptLines([]);
                break;
            case 'transcriptLines':
                setTranscriptLines(data.lines || []);
                break;
            case 'bars':
                setBars(data.rows || []);
                setCrosstalk(data.crosstalk ?? 0);
                break;
            case 'wsStatus':
                setWsStatus(data.text || '');
                break;
            case 'transport':
                setTransport({ mode: data.mode || '', label: data.label || '' });
                break;
            case 'micDevice':
                setMicHint(data.hint || data.label || '');
                break;
            case 'voiceDebug':
                setVoiceDebugData(data);
                break;
            case 'meetingPaused':
                setPaused(!!data.paused);
                break;
            case 'analytics':
                setAnalytics(data);
                break;
            default:
                break;
        }
    }, [studioDebug]);

    useEffect(() => {
        let cancelled = false;
        const session = new MeetingLiveSession({
            api,
            getToken,
            meetingId: id,
            getRelayWsUrl: () => String(settingsRef.current.relayWsUrl || '').trim() || 'ws://127.0.0.1:9001',
            getUseWs: () => !!settingsRef.current.useWs,
            getIntroSeconds: () => settingsRef.current.introSeconds,
            getChunkMs: () => settingsRef.current.chunkMs,
            getMicProfile: () => String(settingsRef.current.micProfile || 'default').trim() || 'default',
            getIntroBindParticipantId: () => introBindParticipantIdRef.current,
            getSkipIntro: () => autoLiveRef.current && consumerRef.current,
            getConsumerMode: () => consumerRef.current,
            emit,
        });
        sessionRef.current = session;
        if (autoLiveRef.current && consumerRef.current && !isLikelyIosSimulator()) {
            session.setIntroMode('ws');
        }

        (async () => {
            try {
                const m = await api(`/meetings/${id}`);
                if (!cancelled) setMeetingTitle(m?.title || 'Meeting');
            } catch (e) {
                if (!cancelled) setErr(e.message);
            }
        })();
        session.refreshParticipants().catch(() => {});

        fetchMeetingServicesHealth(api).then((h) => {
            if (!cancelled) setServicesHealth(h);
        });

        const healthPoll = setInterval(() => {
            fetchMeetingServicesHealth(api).then((h) => {
                if (!cancelled) setServicesHealth(h);
            });
        }, 30_000);

        return () => {
            clearInterval(healthPoll);
            cancelled = true;
            session.dispose();
            sessionRef.current = null;
        };
    }, [id, emit]);

    useEffect(() => {
        introBindParticipantIdRef.current = introBindParticipantId;
    }, [introBindParticipantId]);

    async function handleStartLive() {
        const s = sessionRef.current;
        if (!s) return;
        setErr('');
        setBusy(true);
        try {
            await s.startMeeting();
        } catch (e) {
            const code = e?.code || e?.payload?.code;
            if (code === 'voiceprints_missing' || code === 'voiceprints_too_similar') {
                setErr(
                    `${e?.message || 'Cannot start'} — each speaker needs Voice ok before live (solo: only one person).`,
                );
            } else {
                setErr(e?.message || String(e));
            }
        } finally {
            setBusy(false);
        }
    }

    const soloCanStartWithoutVoice = useMemo(() => {
        const real = participants.filter((p) => !isPlaceholder(p.name));
        return real.length <= 1;
    }, [participants]);

    useEffect(() => {
        if (!autoLive || autoLiveStartedRef.current || busy) return;
        const s = sessionRef.current;
        if (!s || phase !== 'intro') return;
        if (!autoLive && !startEnabled) return;
        autoLiveStartedRef.current = true;
        void handleStartLive();
    }, [autoLive, phase, busy, startEnabled]);

    async function handleEnd() {
        const s = sessionRef.current;
        if (!s || ending) return;
        setErr('');
        setEnding(true);
        try {
            await s.endMeeting();
        } catch (e) {
            setErr(e?.message || String(e));
        } finally {
            setEnding(false);
        }
    }

    return (
        <div className={`flex min-h-[100dvh] flex-col ${consumer ? 'wc-studio' : ''}`}>
            <header className={`sticky top-0 z-20 px-4 pb-3 pt-[calc(0.75rem+env(safe-area-inset-top,0px))] ${consumer ? 'wc-glass border-b border-white/10 bg-slate-900/40 backdrop-blur-xl' : 'border-b border-slate-800/80 bg-slate-950/90 backdrop-blur-md'}`}>
                                <div className="mx-auto flex max-w-2xl items-center gap-3">
                    <Link to={`/meetings/${id}`} className="text-2xl font-light text-slate-500">‹</Link>
                    <div className="min-w-0 flex-1">
                        <h1 className="truncate text-lg font-bold text-white">
                            {consumer ? (phase === 'live' ? 'Recording' : phase === 'analytics' ? 'Done' : 'Getting ready') : 'Studio'}
                        </h1>
                        <p className="truncate text-xs text-slate-500">{meetingTitle || '…'}</p>
                    </div>
                    {phase === 'live' ? <span className="shrink-0 rounded-full bg-emerald-500/20 px-2.5 py-1 text-[0.65rem] font-bold uppercase text-emerald-300">Live</span> : null}
                    {phase === 'analytics' ? <span className="shrink-0 rounded-full bg-cyan-500/20 px-2.5 py-1 text-[0.65rem] font-bold uppercase text-cyan-300">Done</span> : null}
                </div>
            </header>

            <main className="mx-auto w-full max-w-2xl flex-1 space-y-4 px-4 py-4 pb-[max(2rem,env(safe-area-inset-bottom))]">
                {err ? (
                    <div className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">{err}</div>
                ) : null}

                {servicesSummary.message && (!consumer || !servicesSummary.ok) ? (
                    <div className={`rounded-xl border px-4 py-3 text-sm ${servicesSummary.ok ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100' : 'border-amber-500/30 bg-amber-500/10 text-amber-100'}`}>
                        {servicesSummary.message}
                    </div>
                ) : null}

                {dev ? (
                <section className="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
                    <details className="group" open={!servicesSummary.ok}>
                        <summary className="cursor-pointer list-none text-xs font-bold uppercase tracking-wider text-slate-500">Relay settings</summary>
                        <div className="mt-3 space-y-3">
                            <label className="block text-xs text-slate-400">
                                WebSocket relay URL
                                <input value={relayWsUrl} onChange={(e) => setRelayWsUrl(e.target.value)} className="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-xs text-slate-200" />
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-300">
                                <input type="checkbox" checked={useWs} onChange={(e) => setUseWs(e.target.checked)} />
                                Use WebSocket for live audio
                            </label>
                        </div>
                    </details>
                </section>
                ) : null}

                {phase === 'intro' && autoLive && consumer ? (
                    <section className="wc-glass-card p-8 text-center">
                        <span className="wc-live-pill">Starting</span>
                        <p className="mt-4 text-sm text-slate-300">Connecting microphone and live transcription…</p>
                    </section>
                ) : null}

                {phase === 'intro' && !(autoLive && consumer) ? (
                    <section className="rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
                        <h2 className="text-sm font-bold text-white">{consumer ? 'Optional: enroll speakers' : 'Voice intro'}</h2>
                        {!consumer ? (
                            <p className="mt-2 text-xs text-slate-500">Record a short clip per person, then start live meeting.</p>
                        ) : (
                            <p className="mt-2 text-xs text-slate-500">Skip this for solo notes — or enroll voices for better speaker labels.</p>
                        )}
                        <ul className="mt-3 max-h-28 space-y-1 overflow-y-auto text-sm text-slate-400">
                            {participants.length === 0 ? <li>No speakers yet.</li> : participants.map((p) => (
                                <li key={p.id} className="flex justify-between"><span>{p.name}</span>
                                {hasEcapaVoiceprintEmbedding(p) ? <span className="text-emerald-400 text-xs">Voice ok</span> : <span className="text-slate-600 text-xs">pending</span>}
                                </li>
                            ))}
                        </ul>
                        {dev && introLog.length > 0 ? (
                            <pre className="mt-3 max-h-24 overflow-y-auto rounded-lg border border-slate-800 bg-black/30 p-2 font-mono text-[0.65rem] text-slate-500">{introLog.join('\n')}</pre>
                        ) : null}
                        <div className="mt-3 flex flex-wrap gap-2">
                            {(introUi === 'idle' || introUi === 'retry') && (
                                <button type="button" disabled={busy} onClick={() => sessionRef.current?.startIntroEnroll()} className="rounded-xl bg-gradient-to-b from-cyan-400 to-[#1AD0DE] px-4 py-3 text-sm font-bold text-slate-950 disabled:opacity-40">
                                    {introUi === 'retry' ? 'Try again' : 'Enroll speaker'}
                                </button>
                            )}
                            {introUi === 'done' && (
                                <button type="button" onClick={() => sessionRef.current?.introNext()} className="rounded-xl border border-slate-600 bg-slate-800 px-4 py-3 text-sm font-bold text-white">Next person</button>
                            )}
                        </div>
                        <button type="button" disabled={busy || !startEnabled} onClick={handleStartLive} className="mt-4 w-full rounded-xl bg-gradient-to-b from-emerald-400 to-emerald-700 py-4 text-sm font-bold text-white disabled:opacity-40">
                            Start live meeting
                        </button>
                    </section>
                ) : null}

                {phase === 'live' ? (
                    <section className="space-y-4">
                        {micHint && phase === 'live' ? (
                            <div className="rounded-xl border border-amber-400/30 bg-amber-500/10 px-4 py-3 text-xs leading-relaxed text-amber-100">
                                {micHint}
                            </div>
                        ) : null}
                        {consumer && (
                            <RecordingHealth
                                phase={phase}
                                micLevel={introMicLevel}
                                servicesHealth={servicesHealth}
                                transport={transport}
                                hasTranscript={transcriptLines.length > 0}
                                wsStatus={wsStatus}
                                micHint={micHint}
                                collapsed={!micHint}
                            />
                        )}
                        {dev && !consumer && (
                            <RecordingHealth
                                phase={phase}
                                micLevel={introMicLevel}
                                servicesHealth={servicesHealth}
                                transport={transport}
                                hasTranscript={transcriptLines.length > 0}
                                wsStatus={wsStatus}
                                collapsed={false}
                            />
                        )}
                        {consumer ? (
                            <>
                                <div className="wc-glass-card flex items-center justify-between gap-3 px-4 py-3">
                                    <div className="flex items-center gap-3">
                                        <span className="wc-live-pill">Live</span>
                                        <span className="wc-timer font-mono text-2xl font-semibold text-white">{elapsed}</span>
                                        {paused ? <span className="text-xs text-amber-300">Paused</span> : null}
                                    </div>
                                    <button type="button" disabled={ending} onClick={handleEnd} className="rounded-xl border border-red-400/30 bg-red-500/20 px-4 py-2 text-sm font-bold text-red-200 backdrop-blur active:scale-95">End</button>
                                </div>
                                <div className="wc-glass-card p-4">
                                    <TranscriptFeed lines={transcriptLines} variant="dark" />
                                    {transcriptLines.length === 0 ? (
                                        <p className="mt-3 text-center text-xs leading-relaxed text-slate-500">
                                            {micHint ||
                                                'Mac mini: plug USB headset/mic into the Mac (not only the iPhone). Or run the app on a real iPhone for earphone mic.'}
                                        </p>
                                    ) : null}
                                </div>
                                <div className="flex gap-2">
                                    <button type="button" disabled={ending} onClick={() => sessionRef.current?.pause()} className="wc-glass-btn flex-1 py-2.5 text-sm text-slate-200">Pause</button>
                                    <button type="button" disabled={ending} onClick={() => sessionRef.current?.resume()} className="wc-glass-btn flex-1 py-2.5 text-sm text-slate-200">Resume</button>
                                </div>
                            </>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-800 bg-slate-900/40 p-4">
                                    <div>
                                        <p className="text-xs uppercase tracking-wider text-slate-500">Elapsed</p>
                                        <p className="font-mono text-2xl text-white">{elapsed}</p>
                                    </div>
                                    <div className="flex gap-2">
                                        <button type="button" disabled={ending} onClick={() => sessionRef.current?.pause()} className="rounded-xl border border-slate-600 px-4 py-2 text-sm text-slate-200">Pause</button>
                                        <button type="button" disabled={ending} onClick={() => sessionRef.current?.resume()} className="rounded-xl border border-slate-600 px-4 py-2 text-sm text-slate-200">Resume</button>
                                        <button type="button" disabled={ending} onClick={handleEnd} className="rounded-xl bg-red-500/20 px-4 py-2 text-sm font-bold text-red-300">End</button>
                                    </div>
                                </div>
                                <div className="rounded-2xl border border-slate-800 bg-slate-900/30 p-4">
                                    <TranscriptFeed lines={transcriptLines} />
                                </div>
                            </>
                        )}
                        {studioDebug ? (
                            <section className={`space-y-3 ${consumer ? 'wc-glass-card p-4' : 'rounded-2xl border border-slate-800 bg-slate-900/40 p-4'}`}>
                                <details open className="group">
                                    <summary className="cursor-pointer text-xs font-bold uppercase tracking-wider text-cyan-400/90">Voice tuning</summary>
                                    <div className="mt-3">
                                        <StudioVoiceDebugPanel data={voiceDebugData} />
                                    </div>
                                </details>
                                <details open className="group border-t border-white/10 pt-3">
                                    <summary className="cursor-pointer text-xs font-bold uppercase tracking-wider text-slate-400">Live log</summary>
                                    <div className="mt-3">
                                        <StudioLiveLogPanel lines={liveLog} onClear={() => setLiveLog([])} />
                                    </div>
                                </details>
                            </section>
                        ) : null}
                    </section>
                ) : null}

                {phase === 'analytics' ? (
                    <section className="rounded-2xl border border-cyan-500/20 bg-gradient-to-br from-cyan-500/5 to-slate-900/80 p-5">
                        <h2 className="text-lg font-bold text-white">{consumer ? 'Meeting notes' : 'Analytics'}</h2>
                        {analytics?.error ? <p className="mt-2 text-sm text-red-300">{analytics.error}</p> : analytics ? (
                            <div className="mt-4"><MeetingSummary data={analytics.analData} /></div>
                        ) : <p className="mt-2 text-slate-500">Loading…</p>}
                        <Link to={`/meetings/${id}`} className="mt-6 inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-b from-cyan-400 to-[#1AD0DE] py-3 text-sm font-bold text-slate-950">View meeting</Link>
                    </section>
                ) : null}
            </main>
        </div>
    );
}
