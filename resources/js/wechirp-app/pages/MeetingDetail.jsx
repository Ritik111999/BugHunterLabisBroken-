import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '../api.js';
import MeetingSummary from '../components/MeetingSummary.jsx';
import TranscriptFeed from '../components/TranscriptFeed.jsx';
import { formatMeetingDate, formatMeetingStatus, isConsumerMode, parseTranscriptLine, showDevTools } from '../otter/helpers.js';
import { hasEcapaVoiceprintEmbedding } from '../studio/helpers.js';

function isPlaceholderParticipantName(name) {
    return /^(Speaker\s+\d+|speaker_\d+|speaker_unknown|chunk\d+_\S+)$/i.test(String(name || '').trim());
}

export default function MeetingDetail() {
    const { id } = useParams();
    const nav = useNavigate();
    const consumer = isConsumerMode();
    const dev = showDevTools();
    const [meeting, setMeeting] = useState(null);
    const [participants, setParticipants] = useState([]);
    const [transcripts, setTranscripts] = useState([]);
    const [analytics, setAnalytics] = useState(null);
    const [initialLoading, setInitialLoading] = useState(true);
    const [err, setErr] = useState('');
    const [busy, setBusy] = useState(false);

    const realParticipants = useMemo(
        () => participants.filter((p) => !isPlaceholderParticipantName(p?.name)),
        [participants],
    );

    const voiceEnrollmentComplete =
        realParticipants.length < 2 || realParticipants.every((p) => hasEcapaVoiceprintEmbedding(p));

    const studioPath = `/meetings/${id}/studio`;
    const liveStudioPath = `${studioPath}?live=1`;
    const isLive = !!(meeting?.started_at && !meeting?.ended_at);
    const isEnded = !!(meeting?.ended_at || meeting?.status === 'completed');

    const transcriptLines = useMemo(
        () =>
            transcripts.map((t) => {
                const parsed = parseTranscriptLine(t.text);
                return { key: String(t.id), name: parsed.speaker, text: parsed.text };
            }),
        [transcripts],
    );

    async function loadMeeting() {
        const m = await api(`/meetings/${id}`);
        setMeeting(m);
    }

    async function loadParticipants() {
        const out = await api(`/meetings/${id}/participants`);
        const list = Array.isArray(out?.data) ? out.data : [];
        setParticipants(list);
    }

    async function loadTranscripts() {
        try {
            const tx = await api(`/meetings/${id}/transcripts`);
            setTranscripts(Array.isArray(tx?.items) ? [...tx.items].reverse() : []);
        } catch {
            setTranscripts([]);
        }
    }

    async function loadAnalytics() {
        try {
            const data = await api(`/meetings/${id}/analytics`);
            setAnalytics(data);
        } catch {
            setAnalytics(null);
        }
    }

    useEffect(() => {
        let cancelled = false;
        (async () => {
            setInitialLoading(true);
            setErr('');
            try {
                await loadMeeting();
                if (!cancelled) await loadParticipants();
                if (!cancelled) await loadTranscripts();
                if (!cancelled) await loadAnalytics();
            } catch (e) {
                if (!cancelled) setErr(e.message);
            } finally {
                if (!cancelled) setInitialLoading(false);
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [id]);

    async function endMeeting() {
        setBusy(true);
        setErr('');
        try {
            await api(`/meetings/${id}/end`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({}),
            });
            await loadMeeting();
            await loadTranscripts();
            await loadAnalytics();
        } catch (x) {
            setErr(x.message);
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className={`flex min-h-[100dvh] flex-col ${consumer ? 'wc-page' : ''}`}>
            <header className={`sticky top-0 z-20 px-5 py-4 pt-[max(1rem,env(safe-area-inset-top))] ${consumer ? 'wc-glass-header' : 'border-b border-slate-800/80 bg-slate-950/90 backdrop-blur-md'}`}>
                <div className="mx-auto flex max-w-lg items-center gap-3">
                    <Link to="/" className="text-2xl font-light text-slate-500">‹</Link>
                    <div className="min-w-0 flex-1">
                        {initialLoading && !meeting ? (
                            <div className="h-6 max-w-[14rem] animate-pulse rounded-lg bg-slate-800/90" aria-hidden />
                        ) : (
                            <>
                                <h1 className="truncate text-lg font-bold text-white">{meeting?.title || 'Meeting'}</h1>
                                <p className="text-xs text-slate-500">
                                    {formatMeetingStatus(meeting?.status, meeting)}
                                    {meeting?.created_at ? ` · ${formatMeetingDate(meeting.created_at)}` : ''}
                                </p>
                            </>
                        )}
                    </div>
                </div>
            </header>

            <main className="mx-auto w-full max-w-lg flex-1 space-y-5 px-5 py-5 pb-[max(2rem,env(safe-area-inset-bottom))]">
                {err ? (
                    <div className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">{err}</div>
                ) : null}

                {consumer ? (
                    <section className="wc-glass-card p-5">
                        {isLive ? (
                            <>
                                <span className="wc-live-pill">Live</span>
                                <p className="mt-3 text-sm text-slate-400">This meeting is still recording.</p>
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => nav(liveStudioPath)}
                                    className="mt-4 w-full rounded-xl bg-gradient-to-b from-emerald-400 to-emerald-700 py-4 text-sm font-bold text-white"
                                >
                                    Return to recording
                                </button>
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={endMeeting}
                                    className="mt-2 w-full rounded-xl border border-red-500/40 bg-red-500/10 py-3 text-sm font-bold text-red-300"
                                >
                                    End meeting
                                </button>
                            </>
                        ) : (
                            <>
                                <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                                    {formatMeetingStatus(meeting?.status, meeting)}
                                </p>
                                {!isEnded ? (
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={() => nav(liveStudioPath)}
                                        className="wc-record-btn mt-4"
                                    >
                                        <span className="wc-record-dot" aria-hidden />
                                        {isEnded ? 'View recording' : 'Continue recording'}
                                    </button>
                                ) : null}
                            </>
                        )}
                    </section>
                ) : (
                    <section className="rounded-2xl border border-cyan-500/25 bg-gradient-to-br from-cyan-500/10 to-slate-900/80 p-5">
                        <h2 className="text-base font-bold text-white">Meeting studio</h2>
                        <p className="mt-2 text-sm text-slate-400">Voice enrollment, live STT, and analytics.</p>
                        <div className="mt-4 flex flex-col gap-2">
                            <button
                                type="button"
                                disabled={busy || (!isLive && !voiceEnrollmentComplete)}
                                onClick={() => nav(liveStudioPath)}
                                className="w-full rounded-xl bg-gradient-to-b from-emerald-400 to-emerald-700 py-4 text-sm font-bold text-white disabled:opacity-40"
                            >
                                {isLive ? 'Continue live meeting' : 'Start live meeting'}
                            </button>
                            <Link
                                to={studioPath}
                                className="flex w-full items-center justify-center rounded-xl border border-cyan-500/40 bg-cyan-500/10 py-3 text-sm font-semibold text-cyan-200"
                            >
                                Open studio
                            </Link>
                        </div>
                    </section>
                )}

                {(isEnded || analytics) && (
                    <section className="rounded-2xl border border-slate-800 bg-slate-900/40 p-5">
                        <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500">Insights</h2>
                        <div className="mt-3">
                            <MeetingSummary data={analytics} compact />
                        </div>
                    </section>
                )}

                {(isEnded || transcriptLines.length > 0) && (
                    <section className="rounded-2xl border border-slate-800 bg-slate-900/40 p-5">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500">Transcript</h2>
                            <button type="button" onClick={() => loadTranscripts()} className="text-xs font-semibold text-cyan-400">
                                Refresh
                            </button>
                        </div>
                        <TranscriptFeed
                            lines={transcriptLines}
                            emptyHint="No transcript saved yet. Record again to capture speech."
                        />
                    </section>
                )}

                {dev && !consumer ? (
                    <section className="rounded-2xl border border-slate-800 bg-slate-900/40 p-5">
                        <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500">Participants</h2>
                        <ul className="mt-4 space-y-2">
                            {participants.length === 0 ? (
                                <li className="py-4 text-center text-sm text-slate-500">No participants yet.</li>
                            ) : (
                                participants.map((p) => (
                                    <li
                                        key={p.id}
                                        className="flex items-center justify-between rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3"
                                    >
                                        <span className="font-medium text-slate-200">{p.name}</span>
                                        {hasEcapaVoiceprintEmbedding(p) ? (
                                            <span className="text-[0.65rem] font-semibold uppercase text-emerald-400">Voice ok</span>
                                        ) : (
                                            <span className="text-[0.65rem] text-slate-500">Pending</span>
                                        )}
                                    </li>
                                ))
                            )}
                        </ul>
                    </section>
                ) : null}
            </main>
        </div>
    );
}
