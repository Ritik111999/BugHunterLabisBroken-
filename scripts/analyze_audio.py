#!/usr/bin/env python3

"""
Audio analyzer for meeting analytics.

Output JSON to stdout with:
{
  "total_seconds": <int>,              # analyzed window length (after intro trim)
  "speakers": { "Rajat": 12, ... },    # speaking seconds per speaker (diarization)
  "overlap_seconds": <int>             # estimated overlap seconds (crosstalk)
}

Notes:
- If diarization deps are not installed, we fall back to a single "Unknown" speaker
  and overlap_seconds = 0, while still returning a real total_seconds from ffprobe.
- For true per-participant + overlap on single mixed audio, set DEEPGRAM_API_KEY to enable
  server-side diarization, or replace `run_diarization()` with pyannote/whisperx.
"""

import argparse
import json
import math
import os
import subprocess
import sys
import urllib.parse
import urllib.request
import urllib.error
from typing import Dict, Tuple, List, Optional


def run(cmd: list[str], timeout: int) -> subprocess.CompletedProcess:
    return subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=timeout,
        check=False,
        text=True,
    )


def ffprobe_duration_seconds(path: str, timeout: int) -> float:
    # Uses ffprobe to get container duration.
    cp = run(
        [
            "ffprobe",
            "-v",
            "error",
            "-show_entries",
            "format=duration",
            "-of",
            "default=noprint_wrappers=1:nokey=1",
            path,
        ],
        timeout=timeout,
    )
    if cp.returncode != 0:
        raise RuntimeError(cp.stderr.strip() or "ffprobe failed")
    try:
        return float(cp.stdout.strip())
    except ValueError as e:
        raise RuntimeError(f"Invalid ffprobe duration: {cp.stdout!r}") from e


def analyzed_window(total_seconds: float, intro_seconds: float) -> int:
    window = max(0.0, total_seconds - max(0.0, intro_seconds))
    return int(math.floor(window))


def _compute_overlap_seconds(intervals: List[Tuple[float, float]]) -> int:
    """
    Compute overlap where >=2 speakers active using a sweep line.
    intervals: list of (start,end) for ALL speakers combined.
    """
    events: List[Tuple[float, int]] = []
    for s, e in intervals:
        if e > s:
            events.append((s, +1))
            events.append((e, -1))
    events.sort(key=lambda x: (x[0], -x[1]))
    active = 0
    last_t = None
    overlap = 0.0
    for t, delta in events:
        if last_t is not None and active >= 2:
            overlap += max(0.0, t - last_t)
        active += delta
        last_t = t
    return int(math.floor(max(0.0, overlap)))


def run_diarization(path: str, intro_seconds: float, timeout: int, deepgram_key_override: Optional[str] = None) -> Tuple[Dict[str, int], int, Dict[str, str], Optional[float]]:
    """
    Deduces speaker talk time and overlap seconds.

    Provider order:
    - Deepgram (if DEEPGRAM_API_KEY is set)
    - fallback to ImportError (caller will handle)
    """
    deepgram_key = (deepgram_key_override or os.getenv("DEEPGRAM_API_KEY", "")).strip()
    if deepgram_key:
        return run_deepgram_diarization(path, intro_seconds=intro_seconds, timeout=timeout, api_key=deepgram_key)
    raise ImportError("No diarization provider configured")


def run_deepgram_diarization(path: str, intro_seconds: float, timeout: int, api_key: str) -> Tuple[Dict[str, int], int, Dict[str, str], Optional[float]]:
    """
    Uses Deepgram diarization. Returns (speaker_seconds, overlap_seconds).
    Speaker keys are "speaker_0", "speaker_1", ...
    """
    params = {
        "model": "nova-2",
        "diarize": "true",
        "utterances": "true",
        "punctuate": "false",
    }
    url = "https://api.deepgram.com/v1/listen?" + urllib.parse.urlencode(params)

    with open(path, "rb") as f:
        audio_bytes = f.read()

    content_type = "audio/*"
    lower = path.lower()
    if lower.endswith(".webm"):
        # Deepgram can be picky; include codec hint for MediaRecorder chunks.
        content_type = "audio/webm;codecs=opus"
    elif lower.endswith(".ogg") or lower.endswith(".oga"):
        content_type = "audio/ogg;codecs=opus"
    elif lower.endswith(".mp3"):
        content_type = "audio/mpeg"
    elif lower.endswith(".wav"):
        content_type = "audio/wav"
    elif lower.endswith(".m4a") or lower.endswith(".mp4"):
        content_type = "audio/mp4"

    req = urllib.request.Request(
        url=url,
        data=audio_bytes,
        method="POST",
        headers={
            "Authorization": f"Token {api_key}",
            "Content-Type": content_type,
        },
    )

    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            payload = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        body = ""
        try:
            body = e.read().decode("utf-8", errors="replace")
        except Exception:
            body = ""
        msg = f"Deepgram HTTP {getattr(e, 'code', '?')}: {getattr(e, 'reason', '')}".strip()
        if body:
            msg += f" — {body.strip()}"
        raise RuntimeError(msg) from e

    data = json.loads(payload)
    per_speaker_seconds: Dict[str, float] = {}
    per_speaker_text: Dict[str, str] = {}
    all_intervals: List[Tuple[float, float]] = []

    results = (data or {}).get("results") or {}
    utterances = results.get("utterances") or []

    if isinstance(utterances, list) and len(utterances) > 0:
        for u in utterances:
            if not isinstance(u, dict):
                continue
            speaker = u.get("speaker")
            start = float(u.get("start") or 0.0)
            end = float(u.get("end") or 0.0)

            # Trim intro window
            start = max(start - max(0.0, intro_seconds), 0.0)
            end = max(end - max(0.0, intro_seconds), 0.0)
            if end <= start:
                continue

            key = f"speaker_{int(speaker) if speaker is not None else 0}"
            per_speaker_seconds[key] = per_speaker_seconds.get(key, 0.0) + (end - start)
            all_intervals.append((start, end))
            t = str(u.get("transcript") or "").strip()
            if t:
                per_speaker_text[key] = (per_speaker_text.get(key, "") + " " + t).strip()
    else:
        # Fallback: use word-level timings (works even when utterances is absent).
        channels = results.get("channels") or []
        words = []
        try:
            words = channels[0]["alternatives"][0]["words"]
        except Exception:
            words = []

        if not isinstance(words, list) or len(words) == 0:
            # Common when chunk is silence/no speech. Don't fail the pipeline; treat as no speech.
            return {}, 0, {}, 0.0

        for w in words:
            if not isinstance(w, dict):
                continue
            speaker = w.get("speaker")
            start = float(w.get("start") or 0.0)
            end = float(w.get("end") or 0.0)

            start = max(start - max(0.0, intro_seconds), 0.0)
            end = max(end - max(0.0, intro_seconds), 0.0)
            if end <= start:
                continue

            key = f"speaker_{int(speaker) if speaker is not None else 0}"
            per_speaker_seconds[key] = per_speaker_seconds.get(key, 0.0) + (end - start)
            all_intervals.append((start, end))
            t = str(w.get("punctuated_word") or w.get("word") or "").strip()
            if t:
                per_speaker_text[key] = (per_speaker_text.get(key, "") + " " + t).strip()

    # Use rounding so short chunks don't collapse to 0s per speaker.
    speaker_seconds_int = {k: max(1, int(round(v))) for k, v in per_speaker_seconds.items() if v > 0.0}
    overlap_seconds = _compute_overlap_seconds(all_intervals)
    inferred_duration = None
    if len(all_intervals) > 0:
        inferred_duration = max(e for _s, e in all_intervals)
    return speaker_seconds_int, overlap_seconds, per_speaker_text, inferred_duration


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--file", required=True)
    parser.add_argument("--intro-seconds", type=float, default=0.0)
    parser.add_argument("--timeout", type=int, default=60)
    parser.add_argument("--deepgram-key", type=str, default="")
    args = parser.parse_args()

    path = args.file
    if not os.path.exists(path):
        print(json.dumps({"error": "file_not_found"}))
        return 2

    try:
        deepgram_key = (str(args.deepgram_key or "")).strip() or os.getenv("DEEPGRAM_API_KEY", "").strip()
        deepgram_enabled = deepgram_key != ""

        # Prefer ffprobe for duration, but allow Deepgram-only mode when ffprobe isn't available.
        total_after_intro = None
        ffprobe_error = None
        try:
            total = ffprobe_duration_seconds(path, timeout=args.timeout)
            total_after_intro = analyzed_window(total, args.intro_seconds)
        except Exception as e:
            ffprobe_error = e

        try:
            speakers, overlap, speaker_text, inferred_duration = run_diarization(
                path, args.intro_seconds, args.timeout, deepgram_key_override=deepgram_key
            )
        except Exception:
            if deepgram_enabled:
                raise
            if total_after_intro is None:
                # No duration + no diarization means we can't produce anything useful.
                raise ffprobe_error or RuntimeError("No diarization provider configured")
            # Fallback (no provider): we can still provide real total_seconds, but no speaker split.
            speakers = {"Unknown": total_after_intro}
            overlap = 0
            speaker_text = {}
            inferred_duration = float(total_after_intro)

        if total_after_intro is None:
            if inferred_duration is None:
                raise ffprobe_error or RuntimeError("Unable to infer duration")
            total_after_intro = analyzed_window(inferred_duration, 0.0)

        out = {
            "total_seconds": int(total_after_intro),
            "speakers": {str(k): int(v) for k, v in speakers.items()},
            "overlap_seconds": int(overlap),
            "speaker_text": {str(k): str(v) for k, v in (speaker_text or {}).items()},
        }
        print(json.dumps(out))
        return 0
    except Exception as e:
        msg = str(e)
        # Helpful hint when ffprobe is missing and Deepgram key isn't present.
        if ("ffprobe" in msg or "No such file or directory: 'ffprobe'" in msg) and not deepgram_key:
            msg = msg + " (DEEPGRAM_API_KEY not set in analyzer env)"
        # Always print a machine-readable error on stdout (caller parses this),
        # and a human-readable message on stderr (so Process error output isn't empty).
        print(json.dumps({"error": msg}))
        try:
            sys.stderr.write(msg.strip() + "\n")
            sys.stderr.flush()
        except Exception:
            pass
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

