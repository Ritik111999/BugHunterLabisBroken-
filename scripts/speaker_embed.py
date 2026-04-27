#!/usr/bin/env python3

import argparse
import json
import os
import subprocess
import sys
import tempfile
from typing import Any, List, Optional, Tuple


def _run(cmd: List[str], timeout: int = 30) -> subprocess.CompletedProcess:
    return subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=timeout,
        check=False,
        text=True,
    )


def _ffmpeg_to_wav_16k_mono(src_path: str) -> str:
    if not os.path.exists(src_path):
        raise RuntimeError(f"file_not_found: {src_path}")

    fd, out_path = tempfile.mkstemp(prefix="sb_", suffix=".wav")
    os.close(fd)

    # Convert anything (webm/ogg/mp3/wav) → 16kHz mono wav (PCM s16le)
    cmd = [
        "ffmpeg",
        "-hide_banner",
        "-loglevel",
        "error",
        "-y",
        "-i",
        src_path,
        "-ac",
        "1",
        "-ar",
        "16000",
        "-f",
        "wav",
        out_path,
    ]
    p = _run(cmd, timeout=60)
    if p.returncode != 0:
        try:
            os.unlink(out_path)
        except OSError:
            pass
        raise RuntimeError(f"ffmpeg_failed: {p.stderr.strip() or 'unknown'}")

    return out_path


_MODEL: Any = None


def _get_model():
    global _MODEL
    if _MODEL is not None:
        return _MODEL

    try:
        from speechbrain.inference.speaker import SpeakerRecognition  # type: ignore
    except Exception as e:
        raise RuntimeError(
            "missing_deps: install torch/torchaudio/speechbrain"
        ) from e

    # Downloads on first run into ~/.cache/torch/hub or HF cache
    _MODEL = SpeakerRecognition.from_hparams(
        source="speechbrain/spkrec-ecapa-voxceleb",
        savedir=os.environ.get("SPEECHBRAIN_CACHE", "storage/app/speechbrain_models/ecapa"),
    )
    return _MODEL


def _embedding_from_audio(src_path: str) -> Tuple[List[float], int]:
    wav_path: Optional[str] = None
    try:
        wav_path = _ffmpeg_to_wav_16k_mono(src_path)

        import torchaudio  # type: ignore

        waveform, sample_rate = torchaudio.load(wav_path)
        # waveform: [channels, time]
        if waveform.dim() == 2 and waveform.size(0) > 1:
            waveform = waveform.mean(dim=0, keepdim=True)

        model = _get_model()
        emb = model.encode_batch(waveform)  # [batch, 1, dim] or similar
        emb = emb.squeeze().detach().cpu().numpy().astype("float32").tolist()
        return emb, int(sample_rate)
    finally:
        if wav_path:
            try:
                os.unlink(wav_path)
            except OSError:
                pass


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--input", required=True, help="Path to audio file")
    args = ap.parse_args()

    try:
        emb, sr = _embedding_from_audio(args.input)
        sys.stdout.write(
            json.dumps(
                {
                    "ok": True,
                    "provider": "speechbrain",
                    "model": "spkrec-ecapa-voxceleb",
                    "sample_rate": sr,
                    "embedding": emb,
                }
            )
        )
        return 0
    except Exception as e:
        sys.stderr.write(str(e))
        sys.stdout.write(json.dumps({"ok": False, "error": str(e)}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

