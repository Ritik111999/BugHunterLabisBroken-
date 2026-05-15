import { formatElapsed, hasEcapaVoiceprintEmbedding, isPlaceholder, sleep } from './helpers.js';
import { mediaBlobToWav16kMono } from './audioWav.js';

/**
 * Port of meeting-demo live engine: intro enrollment, WS/HTTP live, SSE, analytics.
 * UI is React — this class emits structured events only.
 */
export class MeetingLiveSession {
    constructor({
        api,
        getToken,
        meetingId,
        getRelayWsUrl,
        getUseWs,
        getIntroSeconds,
        getChunkMs,
        getMicProfile,
        getIntroBindParticipantId,
        getSkipIntro,
        getConsumerMode,
        emit,
    }) {
        this.api = api;
        this.getToken = getToken;
        this.meetingId = meetingId;
        this.getRelayWsUrl = getRelayWsUrl;
        this.getUseWs = getUseWs;
        this.getIntroSeconds = getIntroSeconds;
        this.getChunkMs = getChunkMs;
        this.getMicProfile = typeof getMicProfile === 'function' ? getMicProfile : () => 'default';
        this.getIntroBindParticipantId =
            typeof getIntroBindParticipantId === 'function' ? getIntroBindParticipantId : () => null;
        this.getSkipIntro = typeof getSkipIntro === 'function' ? getSkipIntro : () => false;
        this.getConsumerMode = typeof getConsumerMode === 'function' ? getConsumerMode : () => false;
        this.emit = emit;

        this.state = {
            introMode: 'http',
            introChunkIndex: 0,
            participants: [],
            introEnrolling: false,
            introWaitingNext: false,
            meeting: {
                running: false,
                paused: false,
                pausedAt: null,
                totalPausedMs: 0,
                chunkIndex: 0,
                startedAt: null,
                timer: null,
                stream: null,
                recorder: null,
                audioCtx: null,
            },
            ws: { socket: null, connected: false, connectedAt: null, useServerAudioClock: false },
            sse: null,
            transcriptSse: null,
            statsPollTimer: null,
            transcriptPollTimer: null,
            audio: { lastSentAt: 0, keepaliveTimer: null },
        };
        this._barsEls = new Map();
        this._transcriptEls = new Map();
        this._pcmBuf = null;
        this._introAutoCaptionUsed = false;
        this._liveSpeechRec = null;
        this._liveSpeechAccum = '';
        this._liveMicMonitor = null;
        this._transportMode = '';
        this._wsIgnoreClose = false;
        this._lastServerTranscriptAt = 0;
    }

    _emitTransport(mode, label) {
        this._transportMode = mode;
        this.emit('transport', { mode, label });
    }

    async _attachLiveMicMonitor(stream) {
        this._stopLiveMicMonitor();
        if (!stream) return;
        try {
            this._liveMicMonitor = await this._beginMicLevelMonitor(stream);
        } catch {
            // Non-fatal
        }
    }

    _stopLiveMicMonitor() {
        try {
            this._liveMicMonitor?.stop();
        } catch {}
        this._liveMicMonitor = null;
    }

    dispose() {
        if (this._raf != null) {
            cancelAnimationFrame(this._raf);
            this._raf = null;
        }
        this._stopLiveMicMonitor();
        this.stopMic();
        this.stopStatsPolling();
        this.stopElapsedTimer();
        if (this.state.sse) {
            try {
                this.state.sse.close();
            } catch {}
            this.state.sse = null;
        }
        if (this.state.transcriptSse) {
            try {
                this.state.transcriptSse.close();
            } catch {}
            this.state.transcriptSse = null;
        }
        this._barsEls.clear();
        this._transcriptEls.clear();
    }

    /**
     * Apply intro /sync API JSON and update UI. On successful enrollment, advances introChunkIndex.
     */
    async _handleIntroChunkResponse(res, { beforeNames, beforeVoiceById, chunkIdx }) {
        if (res?.status === 'failed') throw new Error(String(res.error || 'analyzer_failed'));

        const heard = Array.isArray(res?.heard) ? res.heard : [];
        const heardWithText = heard.filter((h) => h?.text && String(h.text).trim());
        const sttHint = res?.intro_stt_hint || '';
        const INTRO_STT_HINTS = {
            stt_no_words_normal_level:
                'Cloud speech-to-text returned no words from this clip. The app will try on-device recognition automatically — say “My name is …” clearly when prompted.',
            stt_no_words_quiet:
                'This clip was very quiet on the server. Move closer to the mic, raise input gain, then tap Try again.',
        };

        if (res?.intro_assistant_rejected) {
            this.logIntro('✗ Server rejected device caption — check APP_ENV=local or MEETING_INTRO_ALLOW_ASSISTANT_UTTERANCE.');
        }
        if (res?.intro_assistant_applied) {
            this.logIntro('✓ Merged device caption with this clip for name detection.');
        }
        if (res?.intro_manual_bind_applied) {
            this.logIntro('✓ Used “attach to participant” fallback so enrollment could run without simulator STT (local/dev).');
        }

        if (heardWithText.length > 0) {
            this.logIntro(`✓ Processing finished — ${heardWithText.length} speech line(s) from this clip.`);
            this.emit('introHeard', { segments: heardWithText });
            heardWithText.forEach((h) => {
                this.logIntro(`📝 Heard (${h.label || '?'}): ${h.text}`);
            });
        } else {
            if (res?.intro_pipeline_note) {
                this.logIntro(String(res.intro_pipeline_note));
            } else if (sttHint && INTRO_STT_HINTS[sttHint]) {
                this.logIntro(INTRO_STT_HINTS[sttHint]);
            } else {
                this.logIntro(
                    'No transcribed speech in that clip. Tap Try again, check the mic, and say “My name is …”.',
                );
            }
        }

        if (res?.intro_debug && typeof res.intro_debug === 'object') {
            this.logIntro(`Server debug: ${JSON.stringify(res.intro_debug)}`);
        }

        const enrolled = Array.isArray(res?.enrolled_participants) ? res.enrolled_participants : [];
        if (enrolled.length > 0) {
            const byId = new Map(this.state.participants.map((p) => [String(p.id), p]));
            enrolled.forEach((p) => {
                const id = String(p.id);
                byId.set(id, { id: p.id, name: p.name, voice_embedding: p.voice_embedding ?? null });
            });
            this.state.participants = Array.from(byId.values());
            this.emit('participants', { list: this.state.participants });
        }

        await this.refreshParticipants();
        const newNames = this.state.participants.filter(
            (p) => !isPlaceholder(p.name) && !beforeNames.has(p.name.toLowerCase()),
        );
        const gainedVoiceprint = this.state.participants.some(
            (p) =>
                !isPlaceholder(p.name) &&
                hasEcapaVoiceprintEmbedding(p) &&
                !beforeVoiceById.get(p.id),
        );
        const serverReportedEnroll = enrolled.length > 0;

        if (newNames.length > 0 || gainedVoiceprint || serverReportedEnroll) {
            this.state.introChunkIndex = chunkIdx + 1;
            this.state.introWaitingNext = true;
            let label = 'Enrollment saved';
            if (newNames.length > 0) {
                label = `Enrolled: ${newNames.map((p) => p.name).join(', ')}`;
            } else if (gainedVoiceprint) {
                label = 'Voiceprint updated for an existing participant';
            }
            this.logIntro(`✅ ${label}`);
            if (res?.intro_voiceprint_note) {
                this.logIntro(String(res.intro_voiceprint_note));
            } else if (res?.intro_voiceprint_ready === false) {
                this.logIntro(
                    'Voice fingerprint still pending — you can start a solo live meeting now; for 2+ speakers wait for Voice ok or re-enroll.',
                );
            }
            this.emit('introUi', { mode: 'done' });
            if (!gainedVoiceprint && res?.intro_voiceprint_ready === false) {
                void this._pollVoiceprintAfterEnroll();
            }
        } else {
            if (await this._maybeAutoCaptionAfterEmptyStt(res, { beforeNames, beforeVoiceById, chunkIdx })) {
                return;
            }
            if (sttHint === 'stt_no_words_quiet' || sttHint === 'stt_no_words_normal_level') {
                // Hint already logged above.
            } else {
                this.logIntro(
                    heardWithText.length > 0
                        ? `Heard speech but could not enroll a name from: “${heardWithText.map((h) => h.text).join(' ')}”. Say “My name is …” or only your name (1–3 words), then tap Try again.`
                        : 'No speech detected in that clip — tap Try again, check the mic, and say “My name is …” clearly.',
                );
            }
            this.emit('introUi', { mode: 'retry' });
        }
    }

    /**
     * When Deepgram returns no words, re-upload the same clip with on-device speech text (once per enroll).
     */
    async _maybeAutoCaptionAfterEmptyStt(res, { beforeNames, beforeVoiceById, chunkIdx }) {
        if (this._introAutoCaptionUsed || res?.intro_assistant_applied) {
            return false;
        }
        const heard = Array.isArray(res?.heard) ? res.heard : [];
        const heardWithText = heard.filter((h) => h?.text && String(h.text).trim());
        if (heardWithText.length > 0) {
            return false;
        }
        if (!this._lastIntroReplay?.blob || !this._hasDeviceSpeechRecognition()) {
            return false;
        }
        const sttHint = res?.intro_stt_hint || '';
        const emptyCloudStt =
            sttHint === 'stt_no_words_normal_level' ||
            sttHint === 'stt_no_words_quiet' ||
            (heard.length === 0 && !res?.intro_pipeline_note?.includes('failed'));
        if (!emptyCloudStt) {
            return false;
        }

        this._introAutoCaptionUsed = true;
        this.logIntro('☁️ Cloud STT empty — say your name now (on-device, ~8s). Same audio clip will be sent with that text.');
        await this.tryWebSpeechCaptionForIntro({ auto: true, beforeNames, beforeVoiceById, chunkIdx });
        return true;
    }

    _pickIntroRecorderMime() {
        const candidates = [
            'audio/mp4',
            'audio/aac',
            'audio/webm;codecs=opus',
            'audio/ogg;codecs=opus',
            'audio/webm',
        ];
        for (const c of candidates) {
            if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(c)) {
                return c;
            }
        }
        return 'audio/webm';
    }

    _introFileExtension(mime) {
        if (mime.includes('mp4') || mime.includes('aac')) {
            return 'm4a';
        }
        if (mime.includes('ogg')) {
            return 'ogg';
        }
        return 'webm';
    }

    /**
     * Re-send the last intro clip with text from the browser Speech Recognition API (simulator / WebView workaround).
     * Only works when Laravel allows assistant utterance (APP_ENV=local or MEETING_INTRO_ALLOW_ASSISTANT_UTTERANCE).
     */
    async tryWebSpeechCaptionForIntro(opts = {}) {
        const auto = !!opts.auto;
        const Ctor = globalThis.SpeechRecognition || globalThis.webkitSpeechRecognition;
        if (!Ctor) {
            this.logIntro('✗ Device speech captions are not available in this WebView.');
            return;
        }
        const snap = this._lastIntroReplay;
        if (!snap?.blob) {
            this.logIntro('✗ No recent intro clip to caption — use Enroll speaker first.');
            return;
        }
        if (!auto) {
            this.logIntro('🎤 Speak your name now (device captions, ~12s)…');
        }
        const r = new Ctor();
        r.lang = 'en-US';
        r.interimResults = false;
        r.maxAlternatives = 1;
        const waitMs = auto ? 8000 : 12000;
        const text = await new Promise((resolve) => {
            let settled = false;
            const finish = (v) => {
                if (settled) return;
                settled = true;
                resolve(v);
            };
            r.onresult = (e) => {
                try {
                    finish(String(e.results[0][0].transcript || '').trim());
                } catch {
                    finish('');
                }
            };
            r.onerror = () => finish(null);
            try {
                r.start();
            } catch {
                finish(null);
            }
            setTimeout(() => {
                try {
                    r.stop();
                } catch {}
                finish(null);
            }, waitMs);
        });
        if (!text) {
            this.logIntro(
                auto
                    ? '✗ On-device recognition got no words. Tap Try again or use a physical iPhone for Voice intro.'
                    : '✗ No caption received. Try Enroll speaker again.',
            );
            this.emit('introUi', { mode: 'retry' });
            return;
        }
        this.logIntro(`✓ Device caption: ${text}`);
        await this.replayIntroWithAssistantUtterance(text, opts);
    }

    async replayIntroWithAssistantUtterance(utterance, ctx = null) {
        const snap = this._lastIntroReplay;
        if (!snap?.blob) return;
        const { blob, seconds, ext, chunkIdx, bindParticipantId } = snap;
        const beforeNames = ctx?.beforeNames ?? new Set(snap.beforeNames);
        const beforeVoiceById = ctx?.beforeVoiceById ?? new Map(snap.beforeVoiceById);
        this.state.introEnrolling = true;
        this.emit('introUi', { mode: 'recording' });
        try {
            const fd = new FormData();
            fd.append('chunk_index', String(chunkIdx));
            fd.append('duration_seconds', String(seconds));
            fd.append('audio_input_profile', String(this.getMicProfile() || 'default').trim() || 'default');
            fd.append('audio', blob, `intro.${ext}`);
            fd.append('assistant_utterance', utterance);
            if (bindParticipantId != null) {
                fd.append('bind_participant_id', String(bindParticipantId));
            }
            this.logIntro(`⏫ Re-uploading chunk #${chunkIdx} with device caption → server`);

            const res = await this.api(`/meetings/${this.meetingId}/intro/chunk?sync=1`, {
                method: 'POST',
                body: fd,
                timeout: 120_000,
            });
            await this._handleIntroChunkResponse(res, {
                beforeNames: new Set(beforeNames),
                beforeVoiceById: new Map(beforeVoiceById),
                chunkIdx,
            });
        } catch (e) {
            this.logIntro(`✗ Error: ${e?.message ?? e}`);
            this.emit('introUi', { mode: 'retry' });
        } finally {
            this.state.introEnrolling = false;
            this.emit('startEnabled', { enabled: this.computeStartEnabled() });
        }
    }

    logIntro(line) {
        const t = `[${new Date().toLocaleTimeString()}] ${line}`;
        this.emit('introLog', { line: t });
    }

    logEvent(line) {
        const t = `[${new Date().toLocaleTimeString()}] ${line}`;
        this.emit('eventLog', { line: t });
    }

    setIntroMode(mode) {
        this.state.introMode = mode;
        this.emit('introMode', { mode });
    }

    /** @returns {number | null} */
    _normalizeIntroBindParticipantId() {
        const id = this.getIntroBindParticipantId();
        const n = id == null || id === '' ? NaN : Number(id);
        if (!Number.isFinite(n) || n < 1) {
            return null;
        }
        return Math.floor(n);
    }

    _appendBindParticipantToFormData(fd) {
        const id = this._normalizeIntroBindParticipantId();
        if (id != null) {
            fd.append('bind_participant_id', String(id));
        }
    }

    _isExternalMicLabel(label) {
        return /headset|headphone|earphone|earbud|wired|external|usb|bluetooth|airpods|beats|mic/i.test(
            String(label || ''),
        );
    }

    _audioConstraintsForProfile(profile, deviceId = null) {
        const base = deviceId ? { deviceId: { ideal: deviceId } } : {};
        const wiredLike =
            profile === 'wired' ||
            profile === 'headphones' ||
            profile === 'earphones';
        if (wiredLike) {
            return {
                audio: {
                    ...base,
                    channelCount: { ideal: 1 },
                    echoCancellation: { ideal: false },
                    noiseSuppression: { ideal: false },
                    autoGainControl: { ideal: true },
                },
            };
        }
        if (profile === 'bluetooth') {
            return {
                audio: {
                    ...base,
                    channelCount: { ideal: 1 },
                    echoCancellation: { ideal: true },
                    noiseSuppression: { ideal: false },
                    autoGainControl: { ideal: true },
                },
            };
        }
        return {
            audio: {
                ...base,
                channelCount: { ideal: 1 },
                echoCancellation: { ideal: true },
                noiseSuppression: { ideal: true },
                autoGainControl: { ideal: true },
            },
        };
    }

    async _pickPreferredAudioInputId() {
        if (!navigator.mediaDevices?.enumerateDevices) {
            return null;
        }
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const inputs = devices.filter((d) => d.kind === 'audioinput' && d.deviceId);
            if (inputs.length === 0) {
                return null;
            }
            const profile = String(this.getMicProfile() || 'default').toLowerCase();
            const external = inputs.filter((d) => this._isExternalMicLabel(d.label));
            if (profile === 'wired' || profile === 'headphones' || profile === 'earphones') {
                return (external[0] || inputs[inputs.length - 1])?.deviceId || null;
            }
            if (external.length > 0) {
                return external[0].deviceId;
            }
            return inputs[0]?.deviceId || null;
        } catch {
            return null;
        }
    }

    _emitMicDeviceInfo(stream) {
        const track = stream?.getAudioTracks?.()?.[0];
        const label = track?.label || 'Microphone';
        const settings = track?.getSettings?.() || {};
        const muted = !!track?.muted;
        const enabled = track?.enabled !== false;
        const sim = this._isLikelyIosSimulator();
        let hint = '';
        if (sim) {
            hint =
                'Mac mini has no built-in mic. iOS Simulator uses your Mac’s audio input — not the iPhone. Plug a USB headset or mic into the Mac mini, then System Settings → Sound → Input. Or test on a real iPhone (earphones on the phone). Optional: use iPhone as Mac mic (Continuity) if enabled in Sound settings.';
        } else if (this._isCapacitorIos() && /earphone|headphone|headset/i.test(label)) {
            hint = `Using: ${label}. Speak into the headset mic.`;
        } else if (!enabled || muted) {
            hint = 'Microphone track is muted or disabled — check iOS Settings → Privacy → Microphone for WeChirp.';
        } else {
            hint = label !== 'Microphone' && label !== '' ? `Using: ${label}` : '';
        }
        this.emit('micDevice', { label, muted, enabled, hint, simulator: sim });
        this.logEvent(`Mic: ${label}${muted ? ' (muted)' : ''}${!enabled ? ' (disabled)' : ''}`);
    }

    /**
     * Browser mic constraints tuned for wired vs Bluetooth; picks headset input when available.
     */
    async requestMicStream() {
        const profile = String(this.getMicProfile() || 'default').toLowerCase();

        let permissionStream = null;
        try {
            permissionStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
            throw e;
        }

        const preferredId = await this._pickPreferredAudioInputId();
        if (permissionStream) {
            permissionStream.getTracks().forEach((t) => t.stop());
        }

        const attempts = [];
        if (preferredId) {
            attempts.push(this._audioConstraintsForProfile(profile, preferredId));
            attempts.push(this._audioConstraintsForProfile('wired', preferredId));
        }
        attempts.push(this._audioConstraintsForProfile(profile));
        attempts.push(this._audioConstraintsForProfile('wired'));
        attempts.push({ audio: true });

        let lastErr = null;
        for (const constraints of attempts) {
            try {
                const stream = await navigator.mediaDevices.getUserMedia(constraints);
                this._emitMicDeviceInfo(stream);
                return stream;
            } catch (e) {
                lastErr = e;
            }
        }
        throw lastErr || new Error('getUserMedia failed');
    }

    _isCapacitorIos() {
        const c = typeof window !== 'undefined' ? window.Capacitor : null;
        return !!(c?.isNativePlatform?.() && c?.getPlatform?.() === 'ios');
    }

    _isLikelyIosSimulator() {
        if (typeof navigator === 'undefined') {
            return false;
        }
        const ua = navigator.userAgent || '';
        if (/\bSimulator\b/i.test(ua)) return true;
        if (this._isCapacitorIos() && /Mac OS X|Macintosh/i.test(ua)) return true;
        if (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1) return true;
        return false;
    }

    _hasDeviceSpeechRecognition() {
        return typeof globalThis !== 'undefined' && !!(globalThis.SpeechRecognition || globalThis.webkitSpeechRecognition);
    }

    /**
     * Live mic level during intro record (0–100). Helps catch Bluetooth/Simulator paths that never deliver speech.
     *
     * @returns {Promise<{ peakPct: number, stop: () => void }>}
     */
    async _beginMicLevelMonitor(stream) {
        const track = stream?.getAudioTracks?.()?.[0];
        if (track && track.enabled === false) {
            track.enabled = true;
        }
        const audioCtx = new AudioContext();
        if (audioCtx.state === 'suspended') {
            await audioCtx.resume();
        }
        const source = audioCtx.createMediaStreamSource(stream);
        const analyser = audioCtx.createAnalyser();
        analyser.fftSize = 512;
        analyser.smoothingTimeConstant = 0.35;
        const silent = audioCtx.createGain();
        silent.gain.value = 0.0001;
        source.connect(analyser);
        analyser.connect(silent);
        silent.connect(audioCtx.destination);
        const data = new Uint8Array(analyser.frequencyBinCount);
        let peak = 0;
        let raf = null;
        const tick = () => {
            analyser.getByteTimeDomainData(data);
            let sum = 0;
            let maxDev = 0;
            for (let i = 0; i < data.length; i++) {
                const v = (data[i] - 128) / 128;
                sum += v * v;
                maxDev = Math.max(maxDev, Math.abs(v));
            }
            const rms = Math.sqrt(sum / data.length);
            const pct = Math.min(100, Math.round(Math.max(rms * 900, maxDev * 120)));
            if (pct > peak) {
                peak = pct;
            }
            this.emit('introMicLevel', { pct, peak: peak / 100 });
            raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
        return {
            peakPct: () => peak,
            stop: () => {
                if (raf != null) {
                    cancelAnimationFrame(raf);
                    raf = null;
                }
                this.emit('introMicLevel', { pct: 0, peak: 0 });
                try {
                    silent.disconnect();
                } catch {}
                try {
                    source.disconnect();
                } catch {}
                try {
                    audioCtx.close();
                } catch {}
            },
        };
    }

    /**
     * Capture a short name phrase after the audio clip (Web Speech cannot reliably run in parallel with MediaRecorder on iOS).
     */
    async _captureDeviceCaptionQuick(waitMs = 4500) {
        const Ctor = globalThis.SpeechRecognition || globalThis.webkitSpeechRecognition;
        if (!Ctor) {
            return '';
        }
        const r = new Ctor();
        r.lang = 'en-US';
        r.continuous = false;
        r.interimResults = false;
        r.maxAlternatives = 1;
        return await new Promise((resolve) => {
            let settled = false;
            const finish = (v) => {
                if (settled) return;
                settled = true;
                resolve(typeof v === 'string' ? v.trim() : '');
            };
            r.onresult = (e) => {
                try {
                    finish(String(e.results[0][0].transcript || '').trim());
                } catch {
                    finish('');
                }
            };
            r.onerror = () => finish('');
            try {
                r.start();
            } catch {
                finish('');
            }
            setTimeout(() => {
                try {
                    r.stop();
                } catch {}
                finish('');
            }, waitMs);
        });
    }

    async _pollVoiceprintAfterEnroll() {
        for (let i = 0; i < 6; i++) {
            await sleep(2000);
            await this.refreshParticipants();
            const anyVoice = this.state.participants.some(
                (p) => !isPlaceholder(p.name) && hasEcapaVoiceprintEmbedding(p),
            );
            if (anyVoice) {
                this.logIntro('✅ Voice fingerprint ready.');
                return;
            }
        }
        this.logIntro(
            'Voice fingerprint still missing on server. Check Python/SpeechBrain (scripts/embed_audio.py). Solo live STT still works without Voice ok.',
        );
    }

    async refreshParticipants() {
        const out = await this.api(`/meetings/${this.meetingId}/participants`);
        this.state.participants = (Array.isArray(out?.data) ? out.data : []).map((p) => ({
            id: p.id,
            name: p.name,
            voice_embedding: p.voice_embedding ?? null,
        }));
        this.emit('participants', { list: this.state.participants });
        this.emit('startEnabled', { enabled: this.computeStartEnabled() });
    }

    computeStartEnabled() {
        if (this.getSkipIntro()) return true;
        if (this.state.introMode === 'ws') return true;
        const enrolled = this.state.participants.filter((p) => !isPlaceholder(p.name)).length;
        return enrolled > 0;
    }

    async enrollOneParticipant() {
        if (this.state.introEnrolling || this.state.introWaitingNext) return;
        this._introAutoCaptionUsed = false;
        this.state.introEnrolling = true;
        this.emit('introUi', { mode: 'recording' });
        let stream = null;
        let levelMonitor = null;
        try {
            const seconds = Math.max(3, Math.min(6, parseInt(String(this.getIntroSeconds()), 10) || 6));
            this.logIntro(`▶ Recording ${seconds}s — say: "My name is [name]"`);

            const beforeNames = new Set(
                this.state.participants.filter((p) => !isPlaceholder(p.name)).map((p) => p.name.toLowerCase()),
            );
            const beforeVoiceById = new Map(
                this.state.participants.map((p) => [p.id, hasEcapaVoiceprintEmbedding(p)]),
            );

            stream = await this.requestMicStream();
            levelMonitor = await this._beginMicLevelMonitor(stream);

            const mime = this._pickIntroRecorderMime();
            const ext = this._introFileExtension(mime);

            const recorder = new MediaRecorder(stream, { mimeType: mime });
            const parts = [];
            recorder.ondataavailable = (ev) => {
                if (ev.data?.size > 0) parts.push(ev.data);
            };

            await new Promise((r) => {
                recorder.onstart = r;
                recorder.start();
            });
            await sleep(seconds * 1000);
            const peakMicPct = levelMonitor?.peakPct?.() ?? 0;
            levelMonitor?.stop();
            levelMonitor = null;
            try {
                recorder.stop();
            } catch {}
            const blob = await new Promise((r) => {
                recorder.onstop = () => r(new Blob(parts, { type: mime }));
            });
            try {
                stream.getTracks().forEach((t) => t.stop());
            } catch {}
            stream = null;

            const chunkIdx = this.state.introChunkIndex;

            if (peakMicPct < 4) {
                this.logIntro(
                    'Mic level stayed near zero in the WebView — macOS is probably not sending your Bluetooth headset mic into the Simulator. In System Settings → Sound → Input, speak and confirm the level bars move; if they do not, pick MacBook Microphone and set Simulator → I/O → Audio Input → Mac Microphone, or use a physical iPhone.',
                );
            }

            this.logIntro(`⏫ Uploading ${blob.size} bytes (chunk #${chunkIdx}) → Deepgram`);

            let uploadBlob = blob;
            let uploadExt = ext;
            try {
                uploadBlob = await mediaBlobToWav16kMono(blob);
                uploadExt = 'wav';
                this.logIntro(`Converted clip to 16 kHz WAV (${uploadBlob.size} bytes) for server STT.`);
            } catch (e) {
                this.logIntro(`WAV convert skipped (${e?.message || e}) — uploading original ${ext}.`);
            }

            this._lastIntroReplay = {
                blob: uploadBlob,
                seconds,
                ext: uploadExt,
                chunkIdx,
                beforeNames: [...beforeNames],
                beforeVoiceById: [...beforeVoiceById.entries()],
                bindParticipantId: this._normalizeIntroBindParticipantId(),
            };

            let deviceCaption = '';
            if (this._hasDeviceSpeechRecognition()) {
                this.logIntro('Say your name now (on-device, ~4s) — e.g. “My name is …”');
                deviceCaption = await this._captureDeviceCaptionQuick(4500);
                if (deviceCaption) {
                    this.logIntro(`On-device name: ${deviceCaption}`);
                }
            }

            const fd = new FormData();
            fd.append('chunk_index', String(chunkIdx));
            fd.append('duration_seconds', String(seconds));
            fd.append('audio_input_profile', String(this.getMicProfile() || 'default').trim() || 'default');
            fd.append('audio', uploadBlob, `intro.${uploadExt}`);
            if (deviceCaption) {
                fd.append('assistant_utterance', deviceCaption);
            }
            this._appendBindParticipantToFormData(fd);

            const res = await this.api(`/meetings/${this.meetingId}/intro/chunk?sync=1`, {
                method: 'POST',
                body: fd,
                timeout: 120_000,
            });

            await this._handleIntroChunkResponse(res, { beforeNames, beforeVoiceById, chunkIdx });
        } catch (e) {
            this.logIntro(`✗ Error: ${e?.message ?? e}`);
            this.emit('introUi', { mode: 'idle' });
        } finally {
            try {
                levelMonitor?.stop();
            } catch {}
            try {
                stream?.getTracks().forEach((t) => t.stop());
            } catch {}
            this.state.introEnrolling = false;
            this.emit('startEnabled', { enabled: this.computeStartEnabled() });
        }
    }

    async startIntroEnroll() {
        this.state.introEnrolling = false;
        this.state.introWaitingNext = false;
        await this.enrollOneParticipant();
    }

    introNext() {
        this.state.introWaitingNext = false;
        this.emit('introUi', { mode: 'idle' });
        this.logIntro('— next participant: say your name now —');
    }

    async probeRelay(relayWsBase) {
        const base = String(relayWsBase || '').trim().replace(/\/$/, '');
        if (!base) return false;
        const httpBase = base.replace(/^wss:/i, 'https:').replace(/^ws:/i, 'http:');
        try {
            const ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
            const timer = ctrl ? setTimeout(() => ctrl.abort(), 2500) : null;
            const res = await fetch(`${httpBase}/up`, { method: 'GET', signal: ctrl?.signal });
            if (timer) clearTimeout(timer);
            return res.ok;
        } catch {
            return false;
        }
    }

    startTranscriptPolling() {
        this.stopTranscriptPolling();
        this.state.transcriptPollTimer = setInterval(() => {
            if (!this.state.meeting.running || this.state.meeting.paused) return;
            this.refreshDbTranscripts().catch(() => {});
        }, 1000);
    }

    stopTranscriptPolling() {
        if (this.state.transcriptPollTimer) {
            clearInterval(this.state.transcriptPollTimer);
            this.state.transcriptPollTimer = null;
        }
    }

    startElapsedTimer() {
        this.stopElapsedTimer();
        this.state.meeting.timer = setInterval(() => {
            if (!this.state.meeting.running || !this.state.meeting.startedAt) return;
            if (this.state.meeting.paused) return;
            let ms = Date.now() - this.state.meeting.startedAt - (this.state.meeting.totalPausedMs || 0);
            this.emit('elapsed', { text: formatElapsed(ms) });
        }, 250);
    }

    stopElapsedTimer() {
        if (this.state.meeting.timer) {
            clearInterval(this.state.meeting.timer);
            this.state.meeting.timer = null;
        }
    }

    startHttpLiveTransport() {
        this.state.ws.useServerAudioClock = false;
        this._emitTransport('http', 'Captions via HTTP');
        this.startHttpChunks();
        this.startSse();
        this.emit('transcriptMode', { text: '' });
        this.startStatsPolling();
        this.startTranscriptPolling();
    }

    switchToHttpAfterWsFailure() {
        if (!this.state.meeting.running || this._wsFallbackAttempted) return;
        this._wsFallbackAttempted = true;
        this._wsIgnoreClose = true;
        try {
            this.state.ws.socket?.close();
        } catch {}
        this.state.ws.socket = null;
        this.state.ws.connected = false;
        this.state.ws.connectedAt = null;
        try {
            this.state.meeting.stream?.getTracks().forEach((t) => t.stop());
        } catch {}
        try {
            this.state.meeting.audioCtx?.close();
        } catch {}
        this.state.meeting.stream = null;
        this.state.meeting.audioCtx = null;
        this._pcmBuf = null;
        if (this.state.audio.keepaliveTimer) {
            clearInterval(this.state.audio.keepaliveTimer);
            this.state.audio.keepaliveTimer = null;
        }
        this.logEvent('Relay unreachable — switched to HTTP chunks for live STT');
        this.emit('wsStatus', { text: '' });
        this.startHttpLiveTransport();
        this.startLiveDeviceCaptions();
    }

    async startMeeting() {
        await this.api(`/meetings/${this.meetingId}/start`, { method: 'POST' });

        this.state.introEnrolling = false;
        this.state.introWaitingNext = false;
        this._wsFallbackAttempted = false;

        this.emit('phase', { phase: 'live' });
        this.state.meeting.startedAt = Date.now();
        this.state.meeting.running = true;
        this.state.meeting.paused = false;
        this.state.meeting.pausedAt = null;
        this.state.meeting.totalPausedMs = 0;
        this.state.meeting.chunkIndex = 0;
        this.startElapsedTimer();

        const relayBase = this.getRelayWsUrl().trim().replace(/\/$/, '');
        let relayUp = await this.probeRelay(relayBase);
        if (!relayUp) {
            try {
                const health = await this.api('/health/meeting-services');
                relayUp = !!health?.relay_reachable;
            } catch {
                // keep client probe result
            }
        }
        let useWs = this.getUseWs() && relayUp;
        if (!relayUp) {
            useWs = false;
            this.logEvent(
                `Relay offline at ${relayBase || 'ws://127.0.0.1:9001'} — using HTTP chunks. Run: php artisan deepgram:relay`,
            );
            this.emit('wsStatus', { text: '' });
        } else {
            this.emit('wsStatus', { text: '' });
        }

        try {
            if (useWs) {
                this.state.ws.useServerAudioClock = false;
                this._emitTransport('ws', 'Connecting to live relay…');
                this.startWs();
                this.emit('transcriptMode', { text: '' });
                this.emit('transcriptClear', {});
            } else {
                this.startHttpLiveTransport();
                this.emit('transcriptMode', { text: '' });
            }
            this.startTranscriptSse();
            this.startTranscriptPolling();
        } catch (e) {
            this.logEvent(`Live start error: ${e?.message ?? e}`);
            throw e;
        }
    }

    startLiveDeviceCaptions() {
        this.stopLiveDeviceCaptions();
        const Ctor = globalThis.SpeechRecognition || globalThis.webkitSpeechRecognition;
        if (!Ctor || !this.state.meeting.running) return;
        if (!this._isCapacitorIos() && !this._isLikelyIosSimulator()) return;
        try {
            const r = new Ctor();
            r.lang = 'en-US';
            r.continuous = true;
            r.interimResults = true;
            r.onresult = (e) => {
                if (!this.state.meeting.running || this.state.meeting.paused) return;
                if (this._transportMode === 'ws') return;
                const sinceServer = Date.now() - (this._lastServerTranscriptAt || 0);
                if (sinceServer < 4000) return;
                let interim = '';
                let finalText = '';
                for (let i = e.resultIndex; i < e.results.length; i++) {
                    const t = String(e.results[i][0]?.transcript || '').trim();
                    if (!t) continue;
                    if (e.results[i].isFinal) {
                        finalText += (finalText ? ' ' : '') + t;
                    } else {
                        interim += (interim ? ' ' : '') + t;
                    }
                }
                const show = (finalText || interim).trim();
                if (!show) return;
                this.renderLiveLines([
                    {
                        key: 'device',
                        name: 'You',
                        text: show,
                        placeholder: false,
                    },
                ]);
            };
            r.onerror = () => {};
            r.onend = () => {
                if (this.state.meeting.running && this._liveSpeechRec === r) {
                    try {
                        r.start();
                    } catch {}
                }
            };
            r.start();
            this._liveSpeechRec = r;
            this.logEvent('On-device live captions enabled (Simulator / WebView).');
        } catch (e) {
            this.logEvent(`Device captions unavailable: ${e?.message ?? e}`);
        }
    }

    stopLiveDeviceCaptions() {
        if (this._liveSpeechRec) {
            try {
                this._liveSpeechRec.stop();
            } catch {}
            this._liveSpeechRec = null;
        }
        this._liveSpeechAccum = '';
    }

    startStatsPolling() {
        this.stopStatsPolling();
        this.state.statsPollTimer = setInterval(async () => {
            try {
                if (!this.state.meeting.running || this.state.meeting.paused) return;
                const payload = await this.api(`/meetings/${this.meetingId}/stats`);
                try {
                    this.renderBars(payload);
                } catch (e) {
                    this.logEvent(`Stats render error: ${e?.message ?? e}`);
                }
            } catch {}
        }, 500);
    }

    stopStatsPolling() {
        if (this.state.statsPollTimer) {
            clearInterval(this.state.statsPollTimer);
            this.state.statsPollTimer = null;
        }
    }

    scheduleWsStatsFrame(data) {
        this._pendingStats = data;
        if (this._raf != null) return;
        this._raf = requestAnimationFrame(() => {
            this._raf = null;
            const p = this._pendingStats;
            this._pendingStats = null;
            if (p) {
                try {
                    this.renderBars(p);
                    this.renderVoiceDebug(p);
                } catch (e) {
                    this.logEvent(`Stats render error: ${e?.message ?? e}`);
                }
            }
        });
    }

    renderVoiceDebug(payload) {
        const cfg = payload?.voice_config || null;
        const matching = payload?.voice_matching || null;
        if (!cfg || !matching) return;

        const fallback = matching._fallback_last || null;
        const matchingClean = { ...matching };
        delete matchingClean._fallback_last;

        this.emit('voiceDebug', {
            voiceConfig: cfg,
            matching: matchingClean,
            relayDebug: payload?.relay_debug || null,
            fallback,
            crosstalk: payload?.crosstalk_percentage ?? 0,
            liveAudioSeconds: payload?.live_audio_seconds ?? 0,
            debug: payload?.debug || null,
        });
    }

    renderBars(payload) {
        const parts = payload?.participants || [];
        if (parts.length === 0) return;
        const sorted = [...parts].sort((a, b) => (b.talk_percentage || 0) - (a.talk_percentage || 0));
        const liveSec = Number(payload?.live_audio_seconds || 0);
        const meetingSeconds = Number.isFinite(liveSec) && liveSec > 0 ? liveSec : 0.001;
        const rows = sorted.map((p) => {
            const pid = Number(p.participant_id || 0);
            const key = pid > 0 ? `pid:${pid}` : `label:${String(p.label || p.name || '')}`;
            const secsRaw = p.talk_time_seconds != null ? Number(p.talk_time_seconds) : Number(p.talk_time || 0);
            const secs = Number.isFinite(secsRaw) ? Math.max(0, secsRaw) : 0;
            const pctRaw = (secs / meetingSeconds) * 100;
            const pctMeeting = Number.isFinite(pctRaw) ? Math.max(0, Math.min(100, pctRaw)) : 0;
            return {
                key,
                name: String(p.name || 'Unknown'),
                pct: pctMeeting,
                secs,
            };
        });
        const ct = Number(payload?.crosstalk_percentage || 0);
        const crosstalk = Number.isFinite(ct) ? Math.round(ct) : 0;
        this.emit('bars', { rows, crosstalk });
    }

    renderLiveLines(lines, { fromServer = false } = {}) {
        if (!Array.isArray(lines) || lines.length === 0) return;
        if (fromServer) {
            this._lastServerTranscriptAt = Date.now();
        }
        const out = [];
        lines
            .filter((x) => String(x.text || '').trim())
            .forEach((x) => {
                const label = String(x.label || '');
                const key = label !== '' ? label : String(x.name || x.label || '');
                out.push({
                    key,
                    name: x.name || x.label,
                    text: x.text,
                    placeholder: isPlaceholder(x.name || x.label),
                });
            });
        this.emit('transcriptLines', { lines: out });
    }

    startWs() {
        const token = this.getToken() || '';
        const base = this.getRelayWsUrl().trim().replace(/\/$/, '');
        const wsUrl = `${base}/meetings/${this.meetingId}/live?token=${encodeURIComponent(token)}&format=pcm16&transcript=1`;
        const ws = new WebSocket(wsUrl);
        this.state.ws.socket = ws;
        this.state.ws.connectedAt = null;

        this.emit('wsStatus', { text: '' });

        ws.onopen = () => {
            this.state.ws.connected = true;
            this.state.ws.connectedAt = Date.now();
            this.logEvent('WS connected ✓');
            this._emitTransport('ws', 'Live WebSocket connected');
            this.emit('wsStatus', { text: '' });
            if (this.state.audio.keepaliveTimer) clearInterval(this.state.audio.keepaliveTimer);
            this.state.audio.lastSentAt = Date.now();
            this.state.audio.keepaliveTimer = setInterval(() => {
                if (!this.state.meeting.running) return;
                if (ws.readyState !== WebSocket.OPEN) return;
                const since = Date.now() - (this.state.audio.lastSentAt || 0);
                if (since < 2000) return;
                try {
                    ws.send(new ArrayBuffer(3200));
                    this.state.audio.lastSentAt = Date.now();
                } catch {}
            }, 1000);
        };
        ws.onerror = () => {
            this.logEvent('WS error');
            this.emit('wsStatus', { text: 'WS: error ✗' });
        };
        ws.onclose = (ev) => {
            if (this._wsIgnoreClose) {
                this._wsIgnoreClose = false;
                return;
            }
            const code = ev?.code ?? 0;
            this.state.ws.connected = false;
            this.logEvent(`WS closed (code=${code})`);
            if (!this.state.meeting.running) {
                return;
            }
            if (this._transportMode === 'http') {
                this.emit('wsStatus', { text: '' });
                return;
            }
            if (!this._wsFallbackAttempted) {
                this.emit('wsStatus', { text: 'Switching to HTTP captions…' });
                this.switchToHttpAfterWsFailure();
                return;
            }
            this.emit('wsStatus', { text: '' });
            this.startTranscriptPolling();
        };
        ws.onmessage = (ev) => {
            try {
                const msg = JSON.parse(String(ev.data || ''));
                if (msg?.error) {
                    const hint = msg.hint ? ` — ${msg.hint}` : '';
                    this.logEvent(`WS: ${msg.error}${hint}`);
                    if (msg.error === 'deepgram_key_missing' || msg.error === 'pulse_key_missing') {
                        this.emit('wsStatus', { text: 'WS: STT API key missing (.env)' });
                    }
                    return;
                }
                if (msg?.event === 'stats.updated') this.scheduleWsStatsFrame(msg.data);
                if (msg?.event === 'transcript.updated') {
                    this.renderLiveLines(msg.data?.lines || [], { fromServer: true });
                }
            } catch {}
        };

        this.startWsPcm(ws).catch((e) => {
            this.logEvent(`Mic PCM error: ${e?.message ?? e} — falling back to HTTP chunks`);
            if (this.state.meeting.running && !this._wsFallbackAttempted) {
                this.switchToHttpAfterWsFailure();
            }
        });
    }

    async startWsPcm(ws) {
        const stream = await this.requestMicStream();
        this.state.meeting.stream = stream;
        await this._attachLiveMicMonitor(stream);

        if (!window.AudioWorkletNode) {
            throw new Error('AudioWorklet not supported');
        }

        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        const ctx = new AudioCtx({ sampleRate: 16000 });
        this.state.meeting.audioCtx = ctx;
        if (ctx.state === 'suspended') {
            await ctx.resume();
        }

        const workletCode = `
        class WcPcmTap extends AudioWorkletProcessor {
          process(inputs) {
            const input = inputs[0];
            const ch0 = input && input[0];
            if (ch0 && ch0.length) {
              this.port.postMessage(ch0.slice(0));
            }
            return true;
          }
        }
        registerProcessor('wc-pcm-tap', WcPcmTap);
    `;
        const blobUrl = URL.createObjectURL(new Blob([workletCode], { type: 'application/javascript' }));
        await ctx.audioWorklet.addModule(blobUrl);
        URL.revokeObjectURL(blobUrl);

        const source = ctx.createMediaStreamSource(stream);
        const node = new AudioWorkletNode(ctx, 'wc-pcm-tap');
        source.connect(node);

        const floatToPcm16LE = (f32) => {
            const out = new Int16Array(f32.length);
            for (let i = 0; i < f32.length; i++) {
                const x = Math.max(-1, Math.min(1, f32[i]));
                out[i] = x < 0 ? x * 0x8000 : x * 0x7fff;
            }
            return out.buffer;
        };

        node.port.onmessage = (ev) => {
            if (!this.state.meeting.running) return;
            if (ws.readyState !== WebSocket.OPEN) return;
            const chunk = ev.data;
            if (!(chunk instanceof Float32Array) || chunk.length === 0) return;
            const frameSamples = 640;
            if (!this._pcmBuf) this._pcmBuf = new Float32Array(0);
            const prev = this._pcmBuf;
            const src = new Float32Array(prev.length + chunk.length);
            src.set(prev, 0);
            src.set(chunk, prev.length);
            let offset = 0;
            while (offset + frameSamples <= src.length) {
                const frame = src.subarray(offset, offset + frameSamples);
                const toSend = this.state.meeting.paused ? new Float32Array(frameSamples) : frame;
                try {
                    ws.send(floatToPcm16LE(toSend));
                    this.state.audio.lastSentAt = Date.now();
                } catch {}
                offset += frameSamples;
            }
            this._pcmBuf = src.subarray(offset);
        };

        this.logEvent('Mic streaming (PCM16 16k)');
        if (this.state.introMode === 'ws') {
            this.logEvent('Intro: say “My name is …” at the start of the live session.');
        }
    }

    startHttpChunks() {
        this.requestMicStream().then(async (stream) => {
            this.state.meeting.stream = stream;
            await this._attachLiveMicMonitor(stream);
            const defaultMs = this._isLikelyIosSimulator() ? 3000 : 5000;
            const chunkMs = Math.max(2000, Math.min(15000, parseInt(String(this.getChunkMs()), 10) || defaultMs));
            const mime = MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')
                ? 'audio/ogg;codecs=opus'
                : MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                  ? 'audio/webm;codecs=opus'
                  : 'audio/webm';

            this.logEvent(`Mic HTTP chunks (${chunkMs}ms, ${mime})`);

            (async () => {
                while (this.state.meeting.running) {
                    if (this.state.meeting.paused) {
                        await sleep(200);
                        continue;
                    }
                    const rec = new MediaRecorder(stream, { mimeType: mime });
                    const parts = [];
                    rec.ondataavailable = (ev) => {
                        if (ev.data?.size > 0) parts.push(ev.data);
                    };
                    await new Promise((r) => {
                        rec.onstart = r;
                        rec.start();
                    });
                    await sleep(chunkMs);
                    try {
                        rec.stop();
                    } catch {}
                    const blob = await new Promise((r) => {
                        rec.onstop = () => r(new Blob(parts, { type: mime }));
                    });
                    if (!this.state.meeting.running) break;
                    if (!blob || blob.size === 0) {
                        this.logEvent('Empty chunk – skipping');
                        continue;
                    }
                    const ext = mime.includes('ogg') ? 'ogg' : 'webm';
                    const fd = new FormData();
                    fd.append('meeting_id', String(this.meetingId));
                    fd.append('chunk_index', String(this.state.meeting.chunkIndex++));
                    fd.append('audio_input_profile', String(this.getMicProfile?.() || 'default').trim() || 'default');
                    fd.append('audio', blob, `chunk.${ext}`);
                    try {
                        const res = await this.api('/audio/chunk?sync=1', {
                            method: 'POST',
                            body: fd,
                            timeout: 120_000,
                        });
                        if (res?.status === 'failed') {
                            const msg = 'Chunk failed: ' + (res.error || 'unknown');
                            this.logEvent(msg);
                            this.emit('wsStatus', { text: msg });
                        } else {
                            await this.refreshDbTranscripts();
                        }
                    } catch (e) {
                        const msg = 'Chunk error: ' + e.message;
                        this.logEvent(msg);
                        this.emit('wsStatus', { text: msg });
                        await sleep(500);
                    }
                }
            })();
        }).catch((err) => {
            this.logEvent(`Mic error (HTTP chunks): ${err?.message || String(err)}`);
        });
    }

    startTranscriptSse() {
        if (this.state.transcriptSse) {
            try {
                this.state.transcriptSse.close();
            } catch {}
        }
        const token = this.getToken() || '';
        const url = `/api/meetings/${this.meetingId}/transcripts/stream?token=${encodeURIComponent(token)}`;
        this.state.transcriptSse = new EventSource(url);
        this.state.transcriptSse.addEventListener('transcript.updated', (ev) => {
            try {
                const msg = JSON.parse(String(ev.data || '{}'));
                const lines = msg?.lines || msg?.data?.lines || [];
                if (Array.isArray(lines) && lines.length > 0) {
                    this.renderLiveLines(lines, { fromServer: true });
                }
            } catch {}
        });
        this.state.transcriptSse.onerror = () => {};
    }

    startSse() {
        if (this.state.sse) this.state.sse.close();
        const token = this.getToken() || '';
        const url = `/api/meetings/${this.meetingId}/stats/stream?token=${encodeURIComponent(token)}`;
        this.state.sse = new EventSource(url);
        this.state.sse.addEventListener('stats.updated', (ev) => {
            try {
                this.renderBars(JSON.parse(ev.data));
            } catch {}
        });
        this.state.sse.onerror = () => this.logEvent('SSE error');
    }

    async refreshDbTranscripts() {
        try {
            const tx = await this.api(`/meetings/${this.meetingId}/transcripts`);
            if (Array.isArray(tx?.lines) && tx.lines.length > 0) {
                this.renderLiveLines(tx.lines, { fromServer: true });
                return;
            }
            const items = (tx?.items || []).slice(-12);
            const lines = items
                .map((i) => {
                    const raw = String(i.text || '').trim();
                    if (!raw) return null;
                    const m = raw.match(/^\[([^\]]+)\]\s*(.*)$/s);
                    if (m) {
                        return { key: String(i.id), name: m[1].trim() || 'Speaker', text: m[2].trim() };
                    }
                    return { key: String(i.id), name: 'Speaker', text: raw };
                })
                .filter(Boolean);
            if (lines.length > 0) {
                this.renderLiveLines(lines, { fromServer: true });
            }
        } catch {}
    }

    pause() {
        if (!this.state.meeting.running || this.state.meeting.paused) return;
        this.state.meeting.paused = true;
        this.state.meeting.pausedAt = Date.now();
        this.stopLiveDeviceCaptions();
        this.emit('meetingPaused', { paused: true });
    }

    resume() {
        if (!this.state.meeting.running || !this.state.meeting.paused) return;
        if (this.state.meeting.pausedAt) {
            this.state.meeting.totalPausedMs += Date.now() - this.state.meeting.pausedAt;
        }
        this.state.meeting.paused = false;
        this.state.meeting.pausedAt = null;
        this.startLiveDeviceCaptions();
        this.emit('meetingPaused', { paused: false });
    }

    async endMeeting() {
        this.stopMic();
        if (this.state.sse) {
            try {
                this.state.sse.close();
            } catch {}
            this.state.sse = null;
        }
        if (this.state.transcriptSse) {
            try {
                this.state.transcriptSse.close();
            } catch {}
            this.state.transcriptSse = null;
        }
        this.stopElapsedTimer();
        this.logEvent('Ending meeting…');
        try {
            const durationSeconds = this.state.meeting.startedAt
                ? Math.max(0, (Date.now() - this.state.meeting.startedAt) / 1000)
                : null;
            await this.api(`/meetings/${this.meetingId}/end`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ duration_seconds: durationSeconds }),
            });
        } catch {}
        await sleep(1500);
        await this.loadAnalytics();
    }

    stopMic() {
        this.state.meeting.running = false;
        this._stopLiveMicMonitor();
        this.stopLiveDeviceCaptions();
        this.stopElapsedTimer();
        this.stopStatsPolling();
        this.stopTranscriptPolling();
        try {
            this.state.meeting.recorder?.stop();
        } catch {}
        try {
            this.state.meeting.stream?.getTracks().forEach((t) => t.stop());
        } catch {}
        try {
            this.state.meeting.audioCtx?.close();
        } catch {}
        try {
            this.state.ws.socket?.close();
        } catch {}
        try {
            if (this.state.audio.keepaliveTimer) clearInterval(this.state.audio.keepaliveTimer);
        } catch {}
        this.state.audio.keepaliveTimer = null;
        this.state.ws.socket = null;
        this.state.ws.connected = false;
        this.state.ws.connectedAt = null;
        this.state.ws.useServerAudioClock = false;
        this._wsFallbackAttempted = false;
        this.state.meeting.recorder = null;
        this.state.meeting.stream = null;
        this.state.meeting.audioCtx = null;
        this._pcmBuf = null;
    }

    async loadAnalytics() {
        this.emit('phase', { phase: 'analytics' });
        try {
            const [analytic, participants, txResp] = await Promise.all([
                this.api(`/meetings/${this.meetingId}/analytics`).catch(() => null),
                this.api(`/meetings/${this.meetingId}/participants`).catch(() => ({ data: [] })),
                this.api(`/meetings/${this.meetingId}/transcripts`).catch(() => ({ items: [] })),
            ]);
            const parts = Array.isArray(participants?.data) ? participants.data : [];
            const real = parts.filter((p) => !isPlaceholder(p.name));
            const analData = analytic?.data || analytic || {};
            const txItems = (txResp?.items || []).slice(-8);
            this.emit('analytics', { analData, real, txItems });
        } catch (e) {
            this.emit('analytics', { error: e.message });
        }
    }
}
