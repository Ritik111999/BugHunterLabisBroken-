#!/usr/bin/env python3
"""Verify SpeechBrain ECAPA voiceprint pipeline (run after scripts/setup-voiceprint.sh)."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VENV_PY = ROOT / "scripts" / ".venv" / "bin" / "python"
EMBED = ROOT / "scripts" / "embed_audio.py"


def main() -> int:
    py = VENV_PY if VENV_PY.is_file() else Path(sys.executable)
    if not EMBED.is_file():
        print(json.dumps({"ok": False, "error": "embed_audio.py missing"}))
        return 2

    wav = tempfile.NamedTemporaryFile(suffix=".wav", delete=False)
    wav.close()
    wav_path = wav.name

    try:
        ff = subprocess.run(
            [
                "ffmpeg",
                "-y",
                "-hide_banner",
                "-loglevel",
                "error",
                "-f",
                "lavfi",
                "-i",
                "sine=frequency=440:duration=2",
                "-ac",
                "1",
                "-ar",
                "16000",
                wav_path,
            ],
            capture_output=True,
            text=True,
            timeout=30,
            check=False,
        )
        if ff.returncode != 0:
            print(
                json.dumps(
                    {
                        "ok": False,
                        "error": "ffmpeg_failed",
                        "detail": (ff.stderr or ff.stdout or "")[:500],
                    }
                )
            )
            return 3

        env = os.environ.copy()
        env.update(
            {
                "HF_HOME": str(ROOT / "storage" / "app" / "speechbrain_models" / "huggingface"),
                "SPEECHBRAIN_CACHE": str(ROOT / "storage" / "app" / "speechbrain_models" / "ecapa"),
                "HF_HUB_CACHE": str(ROOT / "storage" / "app" / "speechbrain_models" / "huggingface" / "hub"),
                "TORCH_HOME": str(ROOT / "storage" / "app" / "speechbrain_models" / "torch"),
                "MEETING_EMBED_SKIP_VOICE_GATE": "1",
            }
        )
        proc = subprocess.run(
            [str(py), str(EMBED), "--file", wav_path, "--timeout", "180"],
            capture_output=True,
            text=True,
            timeout=200,
            check=False,
            cwd=str(ROOT),
            env=env,
        )
        out = (proc.stdout or "").strip().splitlines()
        last = out[-1] if out else ""
        try:
            data = json.loads(last) if last else {}
        except json.JSONDecodeError:
            data = {"error": "invalid_json", "stdout": (proc.stdout or "")[:300]}

        if proc.returncode == 0 and isinstance(data.get("embedding"), list) and len(data["embedding"]) >= 32:
            print(
                json.dumps(
                    {
                        "ok": True,
                        "python": str(py),
                        "dim": len(data["embedding"]),
                        "engine": data.get("engine", "speechbrain_ecapa"),
                    }
                )
            )
            return 0

        print(
            json.dumps(
                {
                    "ok": False,
                    "exit_code": proc.returncode,
                    "result": data,
                    "stderr": (proc.stderr or "")[-800:],
                }
            )
        )
        return 1
    finally:
        try:
            Path(wav_path).unlink(missing_ok=True)
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
