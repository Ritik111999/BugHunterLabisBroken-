import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, clearAuth, getUser } from '../api.js';
import { notifyAuth } from '../App.jsx';
import { formatMeetingDate, formatMeetingStatus, isConsumerMode } from '../otter/helpers.js';

export default function Home() {
    const nav = useNavigate();
    const user = getUser();
    const consumer = isConsumerMode();
    const [meetings, setMeetings] = useState([]);
    const [loading, setLoading] = useState(true);
    const [err, setErr] = useState('');
    const [recording, setRecording] = useState(false);

    async function load() {
        setErr('');
        setLoading(true);
        try {
            const data = await api('/meetings');
            setMeetings(Array.isArray(data) ? data : []);
        } catch (e) {
            setErr(e.message);
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        load();
    }, []);

    async function startRecording() {
        setRecording(true);
        setErr('');
        try {
            const label = new Date().toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            });
            const m = await api('/meetings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: `Recording ${label}` }),
            });
            nav(`/meetings/${m.id}/studio?live=1`);
        } catch (x) {
            setErr(x.message);
        } finally {
            setRecording(false);
        }
    }

    function signOut() {
        api('/logout', { method: 'POST' }).catch(() => {});
        clearAuth();
        notifyAuth();
        nav('/login', { replace: true });
    }

    if (consumer) {
        return (
            <div className="wc-page flex min-h-[100dvh] flex-col">
                <header className="wc-glass-header wc-otter-header">
                    <div className="mx-auto flex max-w-lg items-center justify-between gap-3 px-5 pt-[max(0.75rem,env(safe-area-inset-top))]">
                        <div>
                            <h1 className="text-xl font-bold tracking-tight text-slate-900">
                                <span className="wc-brand-gradient">We</span>
                                Chirp
                            </h1>
                            <p className="max-w-[14rem] truncate text-xs text-slate-600">{user?.name || 'Your notes'}</p>
                        </div>
                        <button type="button" onClick={signOut} className="wc-glass-btn px-3 py-1.5 text-xs text-slate-600">
                            Sign out
                        </button>
                    </div>
                </header>

                <main className="wc-stagger mx-auto w-full max-w-lg flex-1 space-y-6 px-5 py-6">
                    <section className="wc-glass-card p-6 text-center">
                        <h2 className="text-lg font-bold text-slate-900">Record a conversation</h2>
                        <p className="mt-2 text-sm text-slate-500">
                            One tap — live transcript, speaker labels, and an AI summary when you finish.
                        </p>
                        <button type="button" disabled={recording} onClick={startRecording} className="wc-record-btn mt-6">
                            <span className="wc-record-dot" aria-hidden />
                            {recording ? 'Starting…' : 'Record'}
                        </button>
                    </section>

                    {err ? (
                        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{err}</div>
                    ) : null}

                    <section>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-xs font-bold uppercase tracking-wider text-slate-400">My notes</h2>
                            <button type="button" onClick={load} className="text-xs font-semibold text-violet-600">
                                Refresh
                            </button>
                        </div>
                        {loading ? (
                            <ul className="space-y-2" aria-busy="true">
                                {[0, 1, 2].map((i) => (
                                    <li key={i} className="wc-skeleton h-16" />
                                ))}
                            </ul>
                        ) : meetings.length === 0 ? (
                            <div className="wc-glass-card py-14 text-center">
                                <p className="text-sm text-slate-500">No recordings yet.</p>
                            </div>
                        ) : (
                            <ul className="space-y-2">
                                {meetings.map((m) => (
                                    <li key={m.id}>
                                        <Link to={`/meetings/${m.id}`} className="wc-glass-card flex items-center justify-between gap-3 px-4 py-4">
                                            <div className="min-w-0">
                                                <div className="truncate font-semibold text-slate-900">{m.title}</div>
                                                <div className="mt-0.5 text-xs text-slate-500">
                                                    {formatMeetingStatus(m.status, m)}
                                                    {m.created_at ? ` · ${formatMeetingDate(m.created_at)}` : ''}
                                                </div>
                                            </div>
                                            <span className="shrink-0 text-slate-400">›</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </main>
            </div>
        );
    }

    return (
        <div className="flex min-h-[100dvh] flex-col">
            <header className="sticky top-0 z-20 border-b border-slate-800/80 bg-slate-950/90 px-5 py-4 pt-[max(1rem,env(safe-area-inset-top))] backdrop-blur-md">
                <div className="mx-auto flex max-w-lg items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-bold">
                            <span className="text-[#1AD0DE]">We</span>Chirp
                        </h1>
                        <p className="max-w-[14rem] truncate text-xs text-slate-400">{user?.name || 'Host'}</p>
                    </div>
                    <button
                        type="button"
                        onClick={signOut}
                        className="rounded-xl border border-slate-700 px-4 py-2 text-xs font-semibold text-slate-300 active:bg-slate-800"
                    >
                        Sign out
                    </button>
                </div>
            </header>

            <main className="mx-auto w-full max-w-lg flex-1 space-y-6 px-5 py-6 pb-[max(2rem,env(safe-area-inset-bottom))]">
                <section className="rounded-2xl border border-slate-800 bg-slate-900/50 p-5 shadow-xl">
                    <h2 className="text-base font-bold text-white">Record a conversation</h2>
                    <p className="mt-1 text-sm text-slate-400">
                        Tap once to start live transcription — like Otter. We capture speech, label speakers when possible, and
                        generate a summary when you end.
                    </p>
                    <button type="button" disabled={recording} onClick={startRecording} className="wc-record-btn mt-5">
                        <span className="wc-record-dot" aria-hidden />
                        {recording ? 'Starting…' : 'Record'}
                    </button>
                </section>

                {err ? (
                    <div className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">{err}</div>
                ) : null}

                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-bold uppercase tracking-wider text-slate-500">Recent notes</h2>
                        <button
                            type="button"
                            onClick={load}
                            className="text-xs font-semibold text-[#1AD0DE] transition-opacity hover:opacity-90 active:opacity-70"
                        >
                            Refresh
                        </button>
                    </div>
                    {loading ? (
                        <ul className="space-y-2" aria-busy="true" aria-label="Loading meetings">
                            {[0, 1, 2].map((i) => (
                                <li
                                    key={i}
                                    className="h-[4.5rem] animate-pulse rounded-2xl border border-slate-800/80 bg-slate-900/50"
                                />
                            ))}
                        </ul>
                    ) : meetings.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-slate-700 bg-slate-900/30 py-16 text-center">
                            <p className="text-sm text-slate-400">No recordings yet.</p>
                            <p className="mt-1 text-xs text-slate-600">Tap Record above to capture your first meeting.</p>
                        </div>
                    ) : (
                        <ul className="space-y-2">
                            {meetings.map((m) => (
                                <li key={m.id}>
                                    <Link
                                        to={`/meetings/${m.id}`}
                                        className="flex items-center justify-between gap-3 rounded-2xl border border-slate-800 bg-slate-900/40 px-4 py-4 transition-colors duration-150 ease-out hover:border-slate-700 hover:bg-slate-900/70 active:bg-slate-800/80"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate font-semibold text-slate-100">{m.title}</div>
                                            <div className="mt-0.5 text-xs text-slate-500">
                                                {formatMeetingStatus(m.status, m)}
                                                {m.created_at ? ` · ${formatMeetingDate(m.created_at)}` : ''}
                                            </div>
                                        </div>
                                        <span className="shrink-0 text-slate-500">›</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </main>
        </div>
    );
}
