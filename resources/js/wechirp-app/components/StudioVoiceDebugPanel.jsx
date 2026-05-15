function fmt(n, digits = 3) {
    const x = Number(n);
    return Number.isFinite(x) ? x.toFixed(digits) : '—';
}

function badgeClass(matched, fast) {
    if (matched && fast) return 'bg-violet-500/20 text-violet-200 border-violet-400/30';
    if (matched) return 'bg-emerald-500/20 text-emerald-200 border-emerald-400/30';
    return 'bg-slate-800/80 text-slate-400 border-slate-700';
}

function Stat({ label, value, warn = false }) {
    return (
        <div className={`rounded-lg border px-2 py-1.5 ${warn ? 'border-amber-500/30 bg-amber-500/10' : 'border-slate-800 bg-slate-950/50'}`}>
            <p className="text-[0.6rem] uppercase tracking-wider text-slate-500">{label}</p>
            <p className={`font-mono text-sm ${warn ? 'text-amber-200' : 'text-slate-200'}`}>{value}</p>
        </div>
    );
}

export default function StudioVoiceDebugPanel({ data }) {
    if (!data) {
        return <p className="text-xs text-slate-500">Waiting for relay stats (start live meeting)…</p>;
    }

    const cfg = data.voiceConfig || {};
    const tuning = cfg.tuning || {};
    const matching = data.matching || {};
    const relayDebug = data.relayDebug || {};
    const fallback = data.fallback || null;

    const labelRows = Object.keys(matching)
        .filter((k) => !k.startsWith('_'))
        .sort()
        .map((label) => {
            const m = matching[label] || {};
            return { label, matched: !!m.matched, fast: !!m.fast_identify, name: m.best_participant_name || '—', score: m.best_score, threshold: m.threshold };
        });

    const autoBound = Array.isArray(relayDebug.auto_bound_labels) ? relayDebug.auto_bound_labels : [];

    return (
        <div className="space-y-4 text-xs">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <Stat label="Audio" value={cfg.format || '—'} />
                <Stat label="Live sec" value={fmt(data.liveAudioSeconds, 1)} />
                <Stat label="Crosstalk" value={`${fmt(data.crosstalk, 0)}%`} />
                <Stat label="Enrolled VP" value={String(relayDebug.enrolled_voiceprints ?? '—')} />
                <Stat label="Diarized labels" value={String(relayDebug.diarized_label_count ?? '—')} />
                <Stat label="Collapsed?" value={relayDebug.diarization_collapsed ? 'yes' : 'no'} warn={!!relayDebug.diarization_collapsed} />
            </div>
            <div>
                <h4 className="mb-2 font-bold uppercase tracking-wider text-slate-500">Thresholds</h4>
                <p className="font-mono text-[0.65rem] leading-relaxed text-slate-400">
                    match {fmt(cfg.threshold)} · margin {fmt(tuning.voiceprint_margin)} · fast {tuning.fast_identify ? 'on' : 'off'} @
                    {fmt(tuning.fast_identify_after_seconds, 1)}s · endpoint {tuning.endpointing_ms}ms
                </p>
            </div>
            {autoBound.length > 0 ? (
                <p className="font-mono text-[0.65rem] text-violet-200">Fast-identified: {autoBound.join(', ')}</p>
            ) : null}
            <div>
                <h4 className="mb-2 font-bold uppercase tracking-wider text-slate-500">Labels</h4>
                {labelRows.length === 0 ? (
                    <p className="text-slate-500">No labels yet.</p>
                ) : (
                    <ul className="max-h-40 space-y-1 overflow-y-auto font-mono text-[0.65rem]">
                        {labelRows.map((row) => (
                            <li key={row.label} className="flex flex-wrap gap-2 text-slate-300">
                                <span>{row.label}</span>
                                <span className={badgeClass(row.matched, row.fast)}>{row.fast ? 'FAST' : row.matched ? 'MATCH' : '—'}</span>
                                <span className="text-slate-200">{row.name}</span>
                                <span className="text-slate-500">s={fmt(row.score)} t={fmt(row.threshold)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {fallback?.best_participant_id != null ? (
                <details className="rounded border border-slate-800 p-2">
                    <summary className="cursor-pointer text-slate-500">Fallback JSON</summary>
                    <pre className="mt-1 overflow-x-auto text-[0.6rem] text-slate-500">{JSON.stringify(fallback, null, 2)}</pre>
                </details>
            ) : null}
        </div>
    );
}
