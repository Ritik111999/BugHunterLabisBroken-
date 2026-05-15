/**
 * Parse Deepgram live JSON into speaker seconds + transcript lines.
 */
export class SttParser {
    constructor() {
        this.speakerSeconds = {};
        this.cumulativeText = {};
        this.livePartial = {};
        this.totalSeconds = 0;
        this.overlapSeconds = 0;
        this.audioCursorSeconds = 0;
        this.seen = new Map();
    }

    setAudioCursor(seconds) {
        this.audioCursorSeconds = Math.max(this.audioCursorSeconds, seconds);
    }

    ingest(jsonText) {
        let data;
        try {
            data = JSON.parse(jsonText);
        } catch {
            return;
        }
        if (!data || typeof data !== 'object') return;

        const isFinal =
            data.is_final === true ||
            data.speech_final === true ||
            String(data.is_final) === 'true';

        const utterances =
            data.utterances ||
            data.channel?.alternatives?.[0]?.utterances ||
            data.results?.utterances;

        if (Array.isArray(utterances) && utterances.length > 0) {
            for (const u of utterances) {
                if (!u || typeof u !== 'object') continue;
                const start = Number(u.start) || 0;
                const end = Number(u.end) || 0;
                if (end <= start) continue;
                const label = u.speaker == null ? 'speaker_unknown' : `speaker_${parseInt(u.speaker, 10)}`;
                const text = String(u.transcript || '').trim();
                if (!text) continue;

                if (!isFinal) {
                    this.livePartial[label] = text;
                    continue;
                }

                const dur = Math.max(0, end - start);
                this.speakerSeconds[label] = (this.speakerSeconds[label] || 0) + dur;
                this.totalSeconds = Math.max(this.totalSeconds, end);
                delete this.livePartial[label];

                const key = `${label}|${start.toFixed(2)}|${end.toFixed(2)}|${text}`;
                if (!this.seen.has(key)) {
                    this.seen.set(key, Date.now());
                    this.cumulativeText[label] = `${this.cumulativeText[label] || ''} ${text}`.trim();
                }
            }
            return;
        }

        const alt = data.channel?.alternatives?.[0];
        const transcript = String(alt?.transcript || '').trim();
        if (transcript && !isFinal) {
            this.livePartial.speaker_0 = transcript;
        }
    }

    buildLines() {
        const labels = new Set([
            ...Object.keys(this.cumulativeText),
            ...Object.keys(this.livePartial),
        ]);
        const lines = [];
        for (const label of labels) {
            const base = String(this.cumulativeText[label] || '').trim();
            const partial = String(this.livePartial[label] || '').trim();
            const text = base === '' ? partial : partial === '' ? base : `${base} ${partial}`.trim();
            if (!text) continue;
            lines.push({
                label,
                name: label,
                text: text.slice(-350),
                participant_id: null,
            });
        }
        return lines.slice(0, 8);
    }

    buildSnapshot(meetingId) {
        const labels = Object.keys(this.speakerSeconds);
        const total = Math.max(
            0.001,
            labels.reduce((s, l) => s + Math.max(0, this.speakerSeconds[l] || 0), 0),
        );
        const participants = labels.map((label) => {
            const sec = Math.max(0, this.speakerSeconds[label] || 0);
            return {
                participant_id: 0,
                label,
                name: label,
                talk_time: Math.floor(sec),
                talk_time_seconds: sec,
                talk_percentage: Math.min(100, Math.round((sec / total) * 1000) / 10),
                times_spoken: 0,
            };
        });

        const timeline = Math.max(1, this.audioCursorSeconds, this.totalSeconds);
        const crosstalk = Math.round((this.overlapSeconds / timeline) * 1000) / 10;

        return {
            meeting_id: meetingId,
            total_participants: participants.length,
            participants,
            crosstalk_percentage: crosstalk,
            live_audio_seconds: this.audioCursorSeconds,
            voice_config: {
                format: 'pcm16',
                threshold: Number(process.env.MEETING_VOICEPRINT_THRESHOLD || 0.78),
                tuning: { relay_engine: 'node' },
            },
            voice_matching: {},
            relay_debug: { relay_engine: 'node', enrolled_voiceprints: null },
        };
    }

    persistPayload(meetingId) {
        return {
            meeting_id: meetingId,
            speaker_seconds: this.speakerSeconds,
            overlap_seconds: this.overlapSeconds,
            audio_cursor_seconds: this.audioCursorSeconds,
            lines: this.buildLines(),
            voice_matching: {},
        };
    }
}
