/**
 * Encode a MediaRecorder blob as 16 kHz mono WAV for reliable server-side STT (Deepgram/ffmpeg).
 */
export async function mediaBlobToWav16kMono(blob) {
    const arrayBuffer = await blob.arrayBuffer();
    const audioCtx = new AudioContext({ sampleRate: 16000 });
    try {
        const decoded = await audioCtx.decodeAudioData(arrayBuffer.slice(0));
        const channels = decoded.numberOfChannels;
        const length = decoded.length;
        const mono = new Float32Array(length);
        for (let c = 0; c < channels; c++) {
            const ch = decoded.getChannelData(c);
            for (let i = 0; i < length; i++) {
                mono[i] += ch[i] / channels;
            }
        }
        return float32ToWavBlob(mono, 16000);
    } finally {
        try {
            await audioCtx.close();
        } catch {}
    }
}

function float32ToWavBlob(samples, sampleRate) {
    const numChannels = 1;
    const bitsPerSample = 16;
    const blockAlign = (numChannels * bitsPerSample) / 8;
    const byteRate = sampleRate * blockAlign;
    const dataSize = samples.length * 2;
    const buffer = new ArrayBuffer(44 + dataSize);
    const view = new DataView(buffer);

    writeString(view, 0, 'RIFF');
    view.setUint32(4, 36 + dataSize, true);
    writeString(view, 8, 'WAVE');
    writeString(view, 12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, numChannels, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, byteRate, true);
    view.setUint16(32, blockAlign, true);
    view.setUint16(34, bitsPerSample, true);
    writeString(view, 36, 'data');
    view.setUint32(40, dataSize, true);

    let offset = 44;
    for (let i = 0; i < samples.length; i++) {
        const s = Math.max(-1, Math.min(1, samples[i]));
        view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7fff, true);
        offset += 2;
    }

    return new Blob([buffer], { type: 'audio/wav' });
}

function writeString(view, offset, str) {
    for (let i = 0; i < str.length; i++) {
        view.setUint8(offset + i, str.charCodeAt(i));
    }
}
