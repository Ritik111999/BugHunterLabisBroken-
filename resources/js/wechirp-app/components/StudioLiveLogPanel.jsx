import { useEffect, useRef } from 'react';

export default function StudioLiveLogPanel({ lines = [], onClear }) {
    const endRef = useRef(null);

    useEffect(() => {
        endRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [lines.length]);

    return (
        <div className="flex flex-col">
            <div className="mb-2 flex items-center justify-between gap-2">
                <span className="text-[0.65rem] font-bold uppercase tracking-wider text-slate-500">
                    {lines.length} events
                </span>
                {onClear ? (
                    <button
                        type="button"
                        onClick={onClear}
                        className="rounded border border-slate-700 px-2 py-0.5 text-[0.65rem] text-slate-400 hover:text-white"
                    >
                        Clear
                    </button>
                ) : null}
            </div>
            <pre className="max-h-52 overflow-y-auto rounded-lg border border-slate-800 bg-black/40 p-2 font-mono text-[0.65rem] leading-relaxed text-slate-400">
                {lines.length === 0 ? (
                    <span className="text-slate-600">Logs appear when WS connects, mic streams, STT events…</span>
                ) : (
                    lines.map((line, i) => (
                        <div key={`${i}-${line}`} className="whitespace-pre-wrap break-words">
                            {line}
                        </div>
                    ))
                )}
                <span ref={endRef} />
            </pre>
        </div>
    );
}
