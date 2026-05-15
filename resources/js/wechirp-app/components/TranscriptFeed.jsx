import { useEffect, useMemo, useRef } from 'react';
import { isPlaceholder } from '../studio/helpers.js';

export default function TranscriptFeed({
    lines = [],
    emptyHint = 'Start speaking — words appear here in real time.',
    variant = 'light',
}) {
    const bottomRef = useRef(null);
    const blockClass = variant === 'dark' ? 'wc-transcript-block' : 'wc-transcript-block wc-transcript-block--light';

    const blocks = useMemo(() => {
        const out = [];
        for (const line of lines) {
            const text = String(line.text || '').trim();
            if (!text) continue;
            const name = line.placeholder || isPlaceholder(line.name) ? 'Speaker' : String(line.name || line.key || 'Speaker');
            const last = out[out.length - 1];
            if (last && last.name === name) {
                last.text = `${last.text} ${text}`.trim();
            } else {
                out.push({ id: `${line.key}-${out.length}`, name, text });
            }
        }
        return out;
    }, [lines]);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, [blocks]);

    if (blocks.length === 0) {
        return (
            <div className="wc-transcript-empty">
                <p>{emptyHint}</p>
            </div>
        );
    }

    return (
        <div className="wc-transcript-feed">
            {blocks.map((b, i) => (
                <article key={b.id} className={blockClass} style={{ animationDelay: `${Math.min(i * 0.05, 0.3)}s` }}>
                    <div className="wc-transcript-speaker">{b.name}</div>
                    <p className="wc-transcript-text">{b.text}</p>
                </article>
            ))}
            <div ref={bottomRef} />
        </div>
    );
}
