import { WebSocket } from 'ws';
import { SttParser } from './stt-parser.mjs';

export class MeetingSession {
    constructor({ meetingId, clientWs, config, bridge }) {
        this.meetingId = meetingId;
        this.clientWs = clientWs;
        this.config = config;
        this.bridge = bridge;
        this.parser = new SttParser();
        this.deepgram = null;
        this.authed = false;
        this.closed = false;
        this.lastUpstreamBinary = Date.now();
        this.pcmBytesSinceVoice = 0;
        this.sampleRate = 16000;
        this.labelPcmBuffer = new Map();
        this.timers = [];
    }

    sendClient(obj) {
        if (this.clientWs.readyState !== WebSocket.OPEN) return;
        try {
            this.clientWs.send(JSON.stringify(obj));
        } catch {}
    }

    async handleAuthMessage(token) {
        try {
            await this.bridge.validateAuth(this.meetingId, token);
            this.authed = true;
            this.sendClient({ event: 'auth.ok' });
            this.connectDeepgram();
            this.startTimers();
        } catch (e) {
            this.sendClient({ error: 'unauthenticated', message: String(e.message || e) });
            this.close();
        }
    }

    connectDeepgram() {
        if (!this.config.deepgramKey) {
            this.sendClient({ error: 'deepgram_key_missing' });
            this.close();
            return;
        }

        const params = new URLSearchParams({
            model: this.config.model,
            diarize: 'true',
            punctuate: 'true',
            smart_format: 'true',
            interim_results: 'true',
            utterances: 'true',
            endpointing: String(this.config.endpointing),
            utterance_end_ms: String(this.config.utteranceEnd),
            language: this.config.language,
            encoding: 'linear16',
            sample_rate: '16000',
            channels: '1',
        });

        const url = `wss://api.deepgram.com/v1/listen?${params}`;
        const dg = new WebSocket(url, {
            headers: { Authorization: `Token ${this.config.deepgramKey}` },
        });
        this.deepgram = dg;

        const reconnect = () => {
            if (this.closed) return;
            this.sendClient({
                event: 'upstream_stt.disconnected',
                provider: 'deepgram',
            });
            setTimeout(() => {
                if (!this.closed) {
                    this.connectDeepgram();
                    this.sendClient({ event: 'upstream_stt.reconnected', provider: 'deepgram' });
                }
            }, 500);
        };

        dg.on('open', () => {
            this.lastUpstreamBinary = Date.now();
        });

        dg.on('message', (data, isBinary) => {
            if (!isBinary) {
                this.parser.ingest(data.toString());
                this.pushTranscriptIfDue(true);
            }
        });

        dg.on('close', reconnect);
        dg.on('error', reconnect);

        this.timers.push(
            setInterval(() => {
                if (!this.deepgram || this.deepgram.readyState !== WebSocket.OPEN) return;
                const since = Date.now() - this.lastUpstreamBinary;
                if (since > 4000) {
                    try {
                        this.deepgram.send(JSON.stringify({ type: 'KeepAlive' }));
                    } catch {}
                }
            }, 4000),
        );
    }

    startTimers() {
        this.timers.push(
            setInterval(() => this.pushStats(), this.config.statsMs),
            setInterval(() => this.pushTranscriptIfDue(false), this.config.transcriptMs),
            setInterval(() => this.flushPersist(), this.config.persistMs),
            setInterval(() => this.flushVoiceChunks(), this.config.voiceChunkSec * 1000),
        );
    }

    pushStats() {
        if (!this.authed) return;
        const snap = this.parser.buildSnapshot(this.meetingId);
        this.sendClient({ event: 'stats.updated', data: snap });
    }

    lastTranscriptFp = '';

    pushTranscriptIfDue(force) {
        const lines = this.parser.buildLines();
        const fp = lines.map((l) => `${l.label}|${l.text}`).join('\n');
        if (!force && fp === this.lastTranscriptFp) return;
        this.lastTranscriptFp = fp;
        if (lines.length === 0) return;
        this.sendClient({
            event: 'transcript.updated',
            data: { meeting_id: this.meetingId, lines },
        });
    }

    async flushPersist() {
        if (!this.authed || this.closed) return;
        try {
            await this.bridge.persist(this.parser.persistPayload(this.meetingId));
        } catch (e) {
            console.warn('[gateway] persist failed', this.meetingId, e.message);
        }
    }

    flushVoiceChunks() {
        if (!this.authed) return;
        for (const [label, chunks] of this.labelPcmBuffer.entries()) {
            if (!chunks.length) continue;
            const totalLen = chunks.reduce((n, b) => n + b.length, 0);
            if (totalLen < 16000) continue;
            const merged = Buffer.concat(chunks);
            this.labelPcmBuffer.set(label, []);
            this.bridge
                .ingestVoiceChunk(
                    this.meetingId,
                    label,
                    merged,
                    this.sampleRate,
                    this.parser.audioCursorSeconds,
                )
                .catch(() => {});
        }
    }

    onBinary(data) {
        if (!this.authed) return;
        if (!this.deepgram || this.deepgram.readyState !== WebSocket.OPEN) return;

        const buf = Buffer.isBuffer(data) ? data : Buffer.from(data);
        try {
            this.deepgram.send(buf);
            this.lastUpstreamBinary = Date.now();
        } catch {}

        const seconds = buf.length / (this.sampleRate * 2);
        this.parser.setAudioCursor(this.parser.audioCursorSeconds + seconds);

        const label = this.parser.livePartial
            ? Object.keys(this.parser.livePartial)[0] || 'speaker_0'
            : 'speaker_0';
        if (!this.labelPcmBuffer.has(label)) {
            this.labelPcmBuffer.set(label, []);
        }
        const arr = this.labelPcmBuffer.get(label);
        arr.push(buf);
        const maxBytes = this.sampleRate * 2 * 6;
        let total = arr.reduce((n, b) => n + b.length, 0);
        while (total > maxBytes && arr.length > 1) {
            total -= arr.shift().length;
        }
    }

    close() {
        if (this.closed) return;
        this.closed = true;
        for (const t of this.timers) clearInterval(t);
        this.timers = [];
        try {
            this.deepgram?.close();
        } catch {}
        this.flushPersist().catch(() => {});
    }
}
