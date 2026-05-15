import { useMemo } from 'react';
import { showDevTools } from '../otter/helpers.js';

function StatusDot({ ok }) {
    return (
        <span
            className={`inline-block h-2 w-2 shrink-0 rounded-full ${ok ? 'bg-emerald-400' : 'bg-amber-400'}`}
            aria-hidden
        />
    );
}

/**
 * Recording health — compact status during live. Hides when captions are flowing and all is well.
 */
export default function RecordingHealth({
    phase = 'intro',
    micLevel = { pct: 0, peak: 0 },
    servicesHealth = null,
    transport = null,
    hasTranscript = false,
    wsStatus = '',
    micHint = '',
    collapsed = false,
}) {
    const dev = showDevTools();

    const rows = useMemo(() => {
        const h = servicesHealth || {};
        const mode = transport?.mode || '';
        const micSignal = (micLevel?.pct ?? 0) > 2 || (micLevel?.peak ?? 0) > 0.02;
        const micOk = phase !== 'live' || micSignal || hasTranscript;
        const sttOk = !!h.stt_configured;

        const wsWarn = /closed|error|failed|switching/i.test(String(wsStatus || ''));

        let relayOk = false;
        let relayDetail = 'Checking…';
        if (mode === 'http') {
            relayOk = sttOk;
            relayDetail = transport?.label || 'HTTP captions (Deepgram)';
        } else if (mode === 'ws' && !wsWarn) {
            relayOk = true;
            relayDetail = transport?.label || 'Live WebSocket';
        } else if (mode === 'ws' && wsWarn) {
            relayOk = false;
            relayDetail = 'Reconnecting via HTTP…';
        } else if (h.relay_reachable) {
            relayOk = true;
            relayDetail = 'Relay reachable';
        } else {
            relayOk = false;
            relayDetail = 'Start: php artisan deepgram:relay';
        }

        const queueOk = (h.queue_audio_depth ?? 0) < 25;
        const openAiOk = !!h.openai_configured;

        const items = [
            {
                key: 'mic',
                label: 'Microphone',
                ok: micOk,
                detail:
                    phase === 'live'
                        ? hasTranscript && !micSignal
                            ? 'Receiving audio'
                            : micHint
                              ? micHint.slice(0, 80)
                              : `${Math.round(micLevel?.pct ?? 0)}% level`
                        : 'Ready when live',
            },
            {
                key: 'stt',
                label: 'Speech-to-text',
                ok: sttOk,
                detail: sttOk ? String(h.stt_provider || 'deepgram') : 'Set DEEPGRAM_API_KEY',
            },
            {
                key: 'relay',
                label: mode === 'http' ? 'Transport' : 'Live relay',
                ok: relayOk,
                detail: relayDetail,
            },
            {
                key: 'queue',
                label: 'Audio queue',
                ok: queueOk,
                detail: `audio: ${h.queue_audio_depth ?? 0}, default: ${h.queue_default_depth ?? 0}`,
            },
        ];

        if (dev) {
            items.push({
                key: 'summary',
                label: 'AI summary',
                ok: openAiOk,
                detail: openAiOk ? 'OpenAI ready' : 'Optional OPENAI_API_KEY',
            });
        }

        return items;
    }, [servicesHealth, micLevel, phase, transport, hasTranscript, dev, wsStatus, micHint]);

    const allOk = rows.every((r) => r.ok);
    const warnText = String(wsStatus || '').trim();

    if (collapsed && allOk && !warnText) {
        return null;
    }

    return (
        <section
            className={`wc-health-panel ${allOk ? 'wc-health-panel--ok' : 'wc-health-panel--warn'}`}
            aria-label="Recording health"
        >
            <div className="wc-health-header">
                <h3 className="wc-health-title">Recording health</h3>
                <span className="wc-health-badge">{allOk ? 'All good' : 'Check items'}</span>
            </div>
            <ul className="wc-health-list">
                {rows.map((r) => (
                    <li key={r.key} className="wc-health-row">
                        <StatusDot ok={r.ok} />
                        <div className="min-w-0 flex-1">
                            <div className="wc-health-label">{r.label}</div>
                            <div className="wc-health-detail">{r.detail}</div>
                        </div>
                    </li>
                ))}
            </ul>
            {warnText ? <p className="wc-health-transport">{warnText}</p> : null}
        </section>
    );
}
