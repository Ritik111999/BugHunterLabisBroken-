export class LaravelBridge {
    constructor(config) {
        this.base = config.laravelUrl;
        this.secret = config.secret;
    }

    headers() {
        return {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Relay-Secret': this.secret,
        };
    }

    async validateAuth(meetingId, token) {
        const res = await fetch(`${this.base}/api/internal/relay/validate-auth`, {
            method: 'POST',
            headers: this.headers(),
            body: JSON.stringify({ meeting_id: meetingId, token }),
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(body.error || `auth_failed_${res.status}`);
        }
        return body;
    }

    async persist(payload) {
        const res = await fetch(`${this.base}/api/internal/relay/persist`, {
            method: 'POST',
            headers: this.headers(),
            body: JSON.stringify(payload),
        });
        if (!res.ok) {
            const body = await res.json().catch(() => ({}));
            throw new Error(body.message || `persist_failed_${res.status}`);
        }
    }

    async ingestVoiceChunk(meetingId, speakerLabel, pcmBuffer, sampleRate, startSeconds) {
        const res = await fetch(`${this.base}/api/internal/relay/voice-chunk`, {
            method: 'POST',
            headers: this.headers(),
            body: JSON.stringify({
                meeting_id: meetingId,
                speaker_label: speakerLabel,
                pcm_base64: Buffer.from(pcmBuffer).toString('base64'),
                sample_rate: sampleRate,
                start_seconds: startSeconds,
            }),
        });
        if (!res.ok) {
            return false;
        }
        return true;
    }
}
