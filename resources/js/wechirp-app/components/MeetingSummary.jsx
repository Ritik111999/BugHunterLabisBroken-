import { sentimentLabel } from '../otter/helpers.js';

export default function MeetingSummary({ data, compact = false }) {
    if (!data) return null;

    const title = String(data.title || data.insights?.title || '').trim();
    const summary = String(data.summary || '').trim();
    const keywords = Array.isArray(data.keywords) ? data.keywords.filter(Boolean) : [];
    const topics = Array.isArray(data.topics) ? data.topics.filter(Boolean) : [];
    const actions = Array.isArray(data.action_items) ? data.action_items.filter(Boolean) : [];
    const decisions = Array.isArray(data.decisions) ? data.decisions.filter(Boolean) : [];
    const followUps = Array.isArray(data.follow_ups) ? data.follow_ups.filter(Boolean) : [];
    const participants = Array.isArray(data.participants) ? data.participants : [];
    const sentiment = sentimentLabel(data.sentiment);
    const crosstalk = data.crosstalk_percentage;

    const hasContent =
        title ||
        summary ||
        keywords.length ||
        topics.length ||
        actions.length ||
        decisions.length ||
        followUps.length ||
        participants.length;
    if (!hasContent) {
        return (
            <p className="text-sm text-slate-500">Summary will appear after the meeting ends and processing finishes.</p>
        );
    }

    return (
        <div className={compact ? 'space-y-4' : 'wc-summary space-y-5'}>
            {title ? (
                <section>
                    <h3 className="wc-summary-label">Meeting</h3>
                    <p className="text-base font-semibold text-slate-800">{title}</p>
                </section>
            ) : null}

            {summary ? (
                <section>
                    <h3 className="wc-summary-label">Summary</h3>
                    <p className="wc-summary-body">{summary}</p>
                </section>
            ) : null}

            {topics.length > 0 ? (
                <section>
                    <h3 className="wc-summary-label">Topics</h3>
                    <div className="wc-keyword-row">
                        {topics.map((t) => (
                            <span key={t} className="wc-keyword-pill">
                                {t}
                            </span>
                        ))}
                    </div>
                </section>
            ) : null}

            {keywords.length > 0 ? (
                <section>
                    <h3 className="wc-summary-label">Keywords</h3>
                    <div className="wc-keyword-row">
                        {keywords.map((k) => (
                            <span key={k} className="wc-keyword-pill">
                                {k}
                            </span>
                        ))}
                    </div>
                </section>
            ) : null}

            {actions.length > 0 ? (
                <section>
                    <h3 className="wc-summary-label">Action items</h3>
                    <ul className="wc-action-list">
                        {actions.map((item, i) => (
                            <li key={`${i}-${item}`}>{item}</li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {decisions.length > 0 ? (
                <section>
                    <h3 className="wc-summary-label">Decisions</h3>
                    <ul className="wc-action-list">
                        {decisions.map((item, i) => (
                            <li key={`d-${i}-${item}`}>{item}</li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {followUps.length > 0 ? (
                <section>
                    <h3 className="wc-summary-label">Follow-ups</h3>
                    <ul className="wc-action-list">
                        {followUps.map((item, i) => (
                            <li key={`f-${i}-${item}`}>{item}</li>
                        ))}
                    </ul>
                </section>
            ) : null}

            {participants.length > 0 ? (
                <section>
                    <h3 className="wc-summary-label">Talk time</h3>
                    <div className="space-y-2">
                        {participants.map((p) => (
                            <div key={p.participant_id || p.name}>
                                    <div className="mb-0.5 flex justify-between text-xs text-slate-400">
                                    <span className="truncate">{p.name}</span>
                                    <span>{Number(p.talk_percentage ?? 0).toFixed(0)}%</span>
                                </div>
                                <div className="h-2 overflow-hidden rounded-full bg-slate-800">
                                    <div
                                        className="h-full rounded-full bg-gradient-to-r from-cyan-400 to-[#1AD0DE]"
                                        style={{ width: `${Math.min(100, Number(p.talk_percentage) || 0)}%` }}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                    {typeof crosstalk === 'number' && crosstalk > 0 ? (
                        <p className="mt-2 text-xs text-slate-500">Overlap detected: {crosstalk.toFixed(0)}%</p>
                    ) : null}
                </section>
            ) : null}

            {sentiment ? (
                <p className="text-xs font-medium text-slate-500">Tone: {sentiment}</p>
            ) : null}
        </div>
    );
}
