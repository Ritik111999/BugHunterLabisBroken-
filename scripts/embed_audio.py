#!/usr/bin/env python3
"""
Compute a normalized speaker embedding (voiceprint) for an audio file.

Outputs JSON to stdout:
{ "embedding": [float, ...], "dim": <int> }

Requirements (recommended):
- SpeechBrain ECAPA-TDNN (speaker embeddings)
- (Optional but recommended) DeepFilterNet (noise suppression)
- (Optional but recommended) Silero VAD (speech-only selection)
- ffmpeg available on PATH
"""

import argparse
import json
import os
import subprocess
import sys
import tempfile
import wave
from array import array
import math
import contextlib

_ECAPA = None
_SILERO_VAD = None


def rms_normalize(samples: list[float], target_rms: float = 0.08) -> list[float]:
    """
    Lightweight normalization to reduce embedding variance across devices.
    target_rms ~0.08 is ~ -22 dBFS for full-scale sine-ish signals.
    """
    if not samples:
        return samples
    s2 = 0.0
    for x in samples:
        s2 += x * x
    rms = math.sqrt(max(1e-12, s2 / max(1, len(samples))))
    # If it's essentially silence, leave unchanged.
    if rms < 1e-4:
        return samples
    gain = target_rms / rms
    # Clamp gain to avoid extreme amplification.
    gain = max(0.25, min(4.0, gain))
    return [max(-1.0, min(1.0, x * gain)) for x in samples]


def compute_rms(samples: list[float]) -> float:
    if not samples:
        return 0.0
    s2 = 0.0
    for x in samples:
        s2 += x * x
    return math.sqrt(max(0.0, s2 / max(1, len(samples))))


def run(cmd: list[str], timeout: int) -> subprocess.CompletedProcess:
    return subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=timeout,
        check=False,
        text=True,
    )


def convert_to_wav_16k_mono(src_path: str, timeout: int, max_seconds: float = 6.0) -> str:
    fd, wav_path = tempfile.mkstemp(prefix="wchirp_embed_", suffix=".wav")
    os.close(fd)
    cp = run(
        [
            "ffmpeg",
            "-y",
            "-hide_banner",
            "-loglevel",
            "error",
            "-i",
            src_path,
            "-t",
            str(float(max_seconds)),
            "-ac",
            "1",
            "-ar",
            "16000",
            "-af",
            "loudnorm=I=-16:LRA=11:TP=-1.5",
            wav_path,
        ],
        timeout=timeout,
    )
    if cp.returncode != 0:
        try:
            os.unlink(wav_path)
        except Exception:
            pass
        raise RuntimeError(cp.stderr.strip() or "ffmpeg convert failed")
    return wav_path


def load_wav_mono_16k(path: str) -> "tuple[list[float], int]":
    """
    Load a mono WAV as float samples in [-1,1]. Uses stdlib only (no torchaudio/torchcodec).
    """
    with wave.open(path, "rb") as wf:
        ch = wf.getnchannels()
        sr = wf.getframerate()
        sampwidth = wf.getsampwidth()
        n = wf.getnframes()
        if ch != 1:
            raise RuntimeError(f"expected mono wav, got channels={ch}")
        if sr != 16000:
            raise RuntimeError(f"expected 16k wav, got sr={sr}")
        if sampwidth != 2:
            raise RuntimeError(f"expected 16-bit PCM wav, got sampwidth={sampwidth}")
        frames = wf.readframes(n)

    pcm = array("h")
    pcm.frombytes(frames)
    if sys.byteorder != "little":
        pcm.byteswap()

    # normalize int16 -> float
    return [max(-1.0, min(1.0, v / 32768.0)) for v in pcm], sr


def l2_normalize(vec: list[float]) -> list[float]:
    s2 = 0.0
    for x in vec:
        s2 += x * x
    n = math.sqrt(max(1e-12, s2))
    return [float(x / n) for x in vec]


def try_deepfilternet(wav: "list[float]", sr: int) -> "list[float]":
    """
    Optional denoise via DeepFilterNet. If unavailable, returns input unchanged.
    """
    try:
        import numpy as np  # type: ignore
        from df.enhance import enhance  # type: ignore
        from df.enhance import init_df  # type: ignore

        # DeepFilterNet expects float32.
        x = np.asarray(wav, dtype=np.float32)
        model, df_state, _ = init_df()
        y = enhance(model, df_state, x)
        out = y.astype(np.float32).tolist()
        return [float(max(-1.0, min(1.0, v))) for v in out]
    except Exception:
        return wav


def try_silero_vad_segments(wav: "list[float]", sr: int) -> "list[tuple[int,int]] | None":
    """
    Optional speech-only segmentation using Silero VAD.
    Returns list of (start_sample, end_sample) in samples @ sr.
    - If Silero is unavailable, returns None (caller should fall back to full audio).
    - If Silero is available but finds no speech, returns [].
    """
    global _SILERO_VAD
    if sr != 16000:
        return None
    try:
        import torch  # type: ignore
        import numpy as np  # type: ignore

        if _SILERO_VAD is None:
            # (model, utils) = torch.hub.load(...)
            # torch.hub can print download logs to stdout; keep stdout JSON-only.
            with contextlib.redirect_stdout(sys.stderr):
                model, utils = torch.hub.load(
                    repo_or_dir="snakers4/silero-vad",
                    model="silero_vad",
                    trust_repo=True,
                )
            get_speech_timestamps = utils[0]
            _SILERO_VAD = (model, get_speech_timestamps)

        model, get_speech_timestamps = _SILERO_VAD
        x = torch.from_numpy(np.asarray(wav, dtype=np.float32))
        ts = get_speech_timestamps(x, model, sampling_rate=16000)
        segs: list[tuple[int, int]] = []
        for t in ts:
            s = int(t.get("start", 0))
            e = int(t.get("end", 0))
            if e > s:
                segs.append((s, e))
        return segs
    except Exception:
        return None


def select_speech_only(wav: "list[float]", sr: int, max_seconds: float) -> "list[float]":
    """
    Keep only speech segments up to max_seconds. Concatenates speech segments.
    """
    if not wav:
        return wav
    max_samps = int(max(0.5, max_seconds) * sr)
    segs = try_silero_vad_segments(wav, sr)
    if segs is None:
        # VAD unavailable → keep a bounded slice of original audio.
        return wav[:max_samps]
    if not segs:
        # VAD ran but detected no speech.
        return []
    out: list[float] = []
    for s, e in segs:
        if s < 0:
            s = 0
        if e > len(wav):
            e = len(wav)
        if e <= s:
            continue
        need = max_samps - len(out)
        if need <= 0:
            break
        chunk = wav[s : min(e, s + need)]
        out.extend(chunk)
        if len(out) >= max_samps:
            break
    return out


def get_ecapa():
    global _ECAPA
    if _ECAPA is not None:
        return _ECAPA
    import torch  # type: ignore
    from speechbrain.inference.speaker import EncoderClassifier  # type: ignore

    # SpeechBrain may print download progress; keep stdout JSON-only.
    with contextlib.redirect_stdout(sys.stderr):
        _ECAPA = EncoderClassifier.from_hparams(
            source="speechbrain/spkrec-ecapa-voxceleb",
            run_opts={"device": "cpu"},
        )
    return _ECAPA


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--file", required=True)
    parser.add_argument("--timeout", type=int, default=60)
    parser.add_argument("--max-seconds", type=float, default=0.0)
    args = parser.parse_args()

    path = args.file
    if not os.path.exists(path):
        print(json.dumps({"error": "file_not_found"}))
        return 2

    wav_path = None
    try:
        result: dict | None = None
        # Many ML libs print download/progress logs to stdout; keep stdout strictly JSON-only.
        with contextlib.redirect_stdout(sys.stderr):
            # If the input is already a valid 16kHz mono PCM16 WAV, skip ffmpeg entirely.
            # This enables PCM streaming pipelines to compute embeddings without ffmpeg.
            samples = None
            try:
                if path.lower().endswith(".wav"):
                    _samps, _sr = load_wav_mono_16k(path)
                    samples = _samps
            except Exception:
                samples = None

            if samples is None:
                max_s = float(args.max_seconds or 0.0)
                max_s = 6.0 if max_s <= 0 else max(1.0, min(60.0, max_s))
                wav_path = convert_to_wav_16k_mono(path, timeout=int(args.timeout), max_seconds=max_s)
                samples, _sr = load_wav_mono_16k(wav_path)

            try:
                import torch  # type: ignore

                # Preprocess: denoise (optional) + speech-only (optional) + RMS normalize
                max_s = float(args.max_seconds or 0.0)
                max_s = 6.0 if max_s <= 0 else max(1.0, min(60.0, max_s))
                samples = try_deepfilternet(samples, 16000)
                samples = select_speech_only(samples, 16000, max_seconds=max_s)
                # Reject silence / no-speech to avoid creating "same for everyone" embeddings.
                if not samples:
                    result = {"error": "voice_missing"}
                    raise RuntimeError("voice_missing")
                rms = compute_rms(samples)
                if rms < 3e-3:
                    result = {"error": "voice_missing", "rms": float(rms)}
                    raise RuntimeError("voice_missing")
                samples = rms_normalize(samples)

                wav = torch.tensor(samples, dtype=torch.float32).unsqueeze(0)  # [1, T]
                classifier = get_ecapa()
                with torch.inference_mode():
                    emb = classifier.encode_batch(wav)  # [1, 1, D] or [1, D]
                    if emb.dim() == 3:
                        emb = emb[0, 0, :]
                    elif emb.dim() == 2:
                        emb = emb[0, :]
                    emb = emb.float()
                    emb = emb / (emb.norm(p=2) + 1e-12)
                vec = [float(x) for x in emb.cpu().tolist()]
                result = {"embedding": vec, "dim": len(vec), "engine": "speechbrain_ecapa"}
            except Exception as e:
                # Preserve explicit voice_missing error; otherwise classify as embedding failure.
                if isinstance(result, dict) and result.get("error") == "voice_missing":
                    pass
                else:
                    result = {"error": f"embedding_engine_missing: {e}"}

        # Print JSON only (no other stdout).
        sys.stdout.write(json.dumps(result or {"error": "unknown"}) + "\n")
        return 0 if result and "embedding" in result else 3
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        return 1
    finally:
        if wav_path:
            try:
                os.unlink(wav_path)
            except Exception:
                pass


if __name__ == "__main__":
    raise SystemExit(main())

