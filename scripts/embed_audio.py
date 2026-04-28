#!/usr/bin/env python3
"""
Compute a normalized speaker embedding (voiceprint) for an audio file.

Outputs JSON to stdout:
{ "embedding": [float, ...], "dim": <int> }

Requirements (recommended):
- SpeechBrain + torch + torchaudio installed in scripts/.venv
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
        try:
            import torch  # type: ignore
            from speechbrain.inference.speaker import EncoderClassifier  # type: ignore
        except Exception as e:
            print(json.dumps({"error": f"speechbrain_missing: {e}"}))
            return 3

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

        # Normalize even for pre-made WAVs (PCM pipeline skips ffmpeg loudnorm).
        samples = rms_normalize(samples)

        wav = torch.tensor(samples, dtype=torch.float32).unsqueeze(0)  # [1, T]

        classifier = EncoderClassifier.from_hparams(
            source="speechbrain/spkrec-ecapa-voxceleb",
            run_opts={"device": "cpu"},
        )

        with torch.inference_mode():
            emb = classifier.encode_batch(wav)  # [1, 1, D] or [1, D]
            if emb.dim() == 3:
                emb = emb[0, 0, :]
            elif emb.dim() == 2:
                emb = emb[0, :]
            emb = emb.float()
            emb = emb / (emb.norm(p=2) + 1e-12)

        vec = [float(x) for x in emb.cpu().tolist()]
        print(json.dumps({"embedding": vec, "dim": len(vec)}))
        return 0
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

