#!/usr/bin/env python3

"""
Audio analyzer for meeting analytics.

Output JSON to stdout with:
{
  "total_seconds": <int>,              # analyzed window length (after intro trim)
  "speakers": { "Rajat": 12, ... },    # speaking seconds per speaker (diarization)
  "overlap_seconds": <int>,            # estimated overlap seconds (crosstalk)
  "speaker_text": { "speaker_0": "..." },
  "speaker_embeddings": { "speaker_0": [<float>, ...] }   # optional (voice recognition)
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
import tempfile
import wave
from array import array
import urllib.parse
import urllib.request
import urllib.error
from typing import Any, Dict, List, Optional, Tuple


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


def run_diarization(
    path: str,
    intro_seconds: float,
    timeout: int,
    deepgram_key_override: Optional[str] = None,
    mode: str = "meeting",
    max_seconds: Optional[float] = None,
) -> Tuple[Dict[str, int], int, Dict[str, str], Dict[str, List[Tuple[float, float]]], Optional[float]]:
    """
    Deduces speaker talk time and overlap seconds.

    Providers (MEETING_STT_PROVIDER):
    - pulse: PULSE_API_KEY → Smallest AI Waves HTTP get_text
    - deepgram (default): DEEPGRAM_API_KEY → Deepgram pre-recorded
    """
    provider = (os.getenv("MEETING_STT_PROVIDER", "deepgram") or "deepgram").strip().lower()
    pulse_key = (os.getenv("PULSE_API_KEY", "")).strip()
    if provider == "pulse" and pulse_key:
        return run_pulse_http_diarization(
            path, intro_seconds=intro_seconds, timeout=timeout, api_key=pulse_key, mode=mode
        )
    deepgram_key = (deepgram_key_override or os.getenv("DEEPGRAM_API_KEY", "")).strip()
    if deepgram_key:
        return run_deepgram_diarization(
            path,
            intro_seconds=intro_seconds,
            timeout=timeout,
            api_key=deepgram_key,
            mode=mode,
            max_seconds=max_seconds,
        )
    raise ImportError("No diarization provider configured")


def _merge_intervals(intervals: List[Tuple[float, float]]) -> List[Tuple[float, float]]:
    if not intervals:
        return []
    intervals = sorted(intervals, key=lambda x: (x[0], x[1]))
    out: List[Tuple[float, float]] = []
    cur_s, cur_e = intervals[0]
    for s, e in intervals[1:]:
        if s <= cur_e:
            cur_e = max(cur_e, e)
        else:
            out.append((cur_s, cur_e))
            cur_s, cur_e = s, e
    out.append((cur_s, cur_e))
    return out


def _convert_to_wav_mono_16k(src_path: str, timeout: int) -> str:
    """
    Convert to a temporary 16kHz mono WAV so torchaudio can load consistently.
    Returns wav path; caller must delete it.
    """
    fd, wav_path = tempfile.mkstemp(prefix="wchirp_", suffix=".wav")
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
            "-ac",
            "1",
            "-ar",
            "16000",
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


def _load_wav_mono_16k(path: str) -> "tuple[list[float], int]":
    """
    Load a mono 16kHz 16-bit PCM WAV as float samples in [-1,1].
    Uses stdlib only (avoids torchaudio TorchCodec dependency).
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

    return [max(-1.0, min(1.0, v / 32768.0)) for v in pcm], sr


def _compute_speaker_embeddings(
    audio_path: str,
    speaker_intervals: Dict[str, List[Tuple[float, float]]],
    intro_seconds: float,
    timeout: int,
) -> Dict[str, List[float]]:
    """
    Compute a voiceprint embedding per speaker label using SpeechBrain ECAPA.
    Returns label -> embedding(float list). If deps missing, returns {}.
    """
    try:
        import torch  # type: ignore
        from speechbrain.inference.speaker import EncoderClassifier  # type: ignore
    except Exception:
        return {}

    wav_path = None
    try:
        wav_path = _convert_to_wav_mono_16k(audio_path, timeout=timeout)
        samples, sr = _load_wav_mono_16k(wav_path)
        wav = torch.tensor(samples, dtype=torch.float32).unsqueeze(0)  # [1, T]

        # Trim intro seconds from the waveform so interval times line up.
        if intro_seconds > 0:
            cut = int(max(0.0, intro_seconds) * sr)
            wav = wav[:, min(cut, wav.shape[1]) :]

        # SpeechBrain model (downloads on first run).
        classifier = EncoderClassifier.from_hparams(
            source="speechbrain/spkrec-ecapa-voxceleb",
            run_opts={"device": "cpu"},
        )

        out: Dict[str, List[float]] = {}
        for label, intervals in speaker_intervals.items():
            merged = _merge_intervals([(float(s), float(e)) for s, e in intervals if e > s])
            if not merged:
                continue

            # Concatenate up to ~20s of audio to avoid huge compute on long chunks.
            pieces = []
            total_s = 0.0
            for s, e in merged:
                if total_s >= 20.0:
                    break
                s_i = int(max(0.0, s) * sr)
                e_i = int(max(0.0, e) * sr)
                if e_i <= s_i:
                    continue
                seg = wav[:, s_i:e_i]
                dur = (e_i - s_i) / sr
                if dur < 0.5:
                    continue
                pieces.append(seg)
                total_s += dur

            if not pieces:
                continue

            audio = torch.cat(pieces, dim=1)  # [1, T]
            if audio.shape[1] < int(1.0 * sr):
                continue

            with torch.inference_mode():
                emb = classifier.encode_batch(audio)  # [1, 1, D] or [1, D]
                if emb.dim() == 3:
                    emb = emb[0, 0, :]
                elif emb.dim() == 2:
                    emb = emb[0, :]
                emb = emb.float()
                emb = emb / (emb.norm(p=2) + 1e-12)

            out[str(label)] = [float(x) for x in emb.cpu().tolist()]

        return out
    finally:
        if wav_path:
            try:
                os.unlink(wav_path)
            except Exception:
                pass


def _compute_global_embedding(audio_path: str, intro_seconds: float, timeout: int, max_seconds: Optional[float] = None) -> Optional[List[float]]:
    """
    Compute a single normalized voiceprint for the whole clip (after intro trim).
    Used to speed up intro enrollment when diarization intervals are missing.
    """
    try:
        import torch  # type: ignore
        from speechbrain.inference.speaker import EncoderClassifier  # type: ignore
    except Exception:
        return None

    wav_path = None
    try:
        wav_path = _convert_to_wav_mono_16k_loudnorm(audio_path, timeout=timeout, max_seconds=max_seconds)
        samples, sr = _load_wav_mono_16k(wav_path)
        wav = torch.tensor(samples, dtype=torch.float32).unsqueeze(0)  # [1, T]

        if intro_seconds > 0:
            cut = int(max(0.0, intro_seconds) * sr)
            wav = wav[:, min(cut, wav.shape[1]) :]

        if wav.shape[1] < int(1.0 * sr):
            return None

        classifier = EncoderClassifier.from_hparams(
            source="speechbrain/spkrec-ecapa-voxceleb",
            run_opts={"device": "cpu"},
        )

        with torch.inference_mode():
            emb = classifier.encode_batch(wav)
            if emb.dim() == 3:
                emb = emb[0, 0, :]
            elif emb.dim() == 2:
                emb = emb[0, :]
            emb = emb.float()
            emb = emb / (emb.norm(p=2) + 1e-12)

        return [float(x) for x in emb.cpu().tolist()]
    finally:
        if wav_path:
            try:
                os.unlink(wav_path)
            except Exception:
                pass


def _convert_to_wav_mono_16k_loudnorm(src_path: str, timeout: int, max_seconds: Optional[float] = None) -> str:
    """
    Convert to a temporary 16kHz mono WAV and normalize loudness.
    This makes quiet mic recordings transcribe better.
    """
    fd, wav_path = tempfile.mkstemp(prefix="wchirp_norm_", suffix=".wav")
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
            *([] if max_seconds is None else ["-t", str(float(max_seconds))]),
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
        raise RuntimeError(cp.stderr.strip() or "ffmpeg loudnorm failed")
    return wav_path


def run_deepgram_diarization(
    path: str,
    intro_seconds: float,
    timeout: int,
    api_key: str,
    mode: str = "meeting",
    max_seconds: Optional[float] = None,
) -> Tuple[Dict[str, int], int, Dict[str, str], Dict[str, List[Tuple[float, float]]], Optional[float]]:
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

    # For intro enrollment, normalize loudness to avoid "speak very loud" issues.
    # We send WAV to Deepgram for maximum compatibility.
    temp_norm = None
    send_path = path
    if str(mode).lower() == "intro":
        # Cap to the requested intro duration to keep enrollment snappy.
        # NOTE: Loudnorm is helpful but can be slow on longer clips; keep it bounded.
        temp_norm = _convert_to_wav_mono_16k_loudnorm(path, timeout=timeout, max_seconds=max_seconds)
        send_path = temp_norm

    with open(send_path, "rb") as f:
        audio_bytes = f.read()

    lower = send_path.lower()
    if lower.endswith(".wav"):
        content_type = "audio/wav"
    elif lower.endswith(".webm"):
        content_type = "audio/webm;codecs=opus"
    elif lower.endswith(".ogg") or lower.endswith(".oga"):
        content_type = "audio/ogg;codecs=opus"
    elif lower.endswith(".mp3"):
        content_type = "audio/mpeg"
    elif lower.endswith(".m4a") or lower.endswith(".mp4"):
        content_type = "audio/mp4"
    else:
        content_type = "audio/*"

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
    per_speaker_intervals: Dict[str, List[Tuple[float, float]]] = {}
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
            per_speaker_intervals.setdefault(key, []).append((start, end))
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
            # If Deepgram didn't return word timings, we may still have a plain transcript.
            # Use it so "my name is X" can work even without diarization timings.
            transcript = ""
            try:
                transcript = str(channels[0]["alternatives"][0].get("transcript") or "").strip()
            except Exception:
                transcript = ""

            if transcript:
                return {}, 0, {"speaker_0": transcript}, {}, 0.0

            # Common when chunk is silence/no speech. Don't fail the pipeline; treat as no speech.
            return {}, 0, {}, {}, 0.0

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
            per_speaker_intervals.setdefault(key, []).append((start, end))
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
    try:
        return speaker_seconds_int, overlap_seconds, per_speaker_text, per_speaker_intervals, inferred_duration
    finally:
        if temp_norm:
            try:
                os.unlink(temp_norm)
            except Exception:
                pass


def run_pulse_http_diarization(
    path: str,
    intro_seconds: float,
    timeout: int,
    api_key: str,
    mode: str = "meeting",
) -> Tuple[Dict[str, int], int, Dict[str, str], Dict[str, List[Tuple[float, float]]], Optional[float]]:
    """
    Smallest AI Pulse pre-recorded: POST linear16 WAV to get_text.
    Returns the same tuple shape as run_deepgram_diarization.
    """
    lang = (os.getenv("PULSE_LANGUAGE", "en") or "en").strip()
    params = {
        "language": lang,
        "word_timestamps": "true",
        "sentence_timestamps": "true",
        "diarize": "true",
        "numerals": "auto",
    }
    url = "https://api.smallest.ai/waves/v1/pulse/get_text?" + urllib.parse.urlencode(params)

    temp_path: Optional[str] = None
    try:
        if str(mode).lower() == "intro":
            temp_path = _convert_to_wav_mono_16k_loudnorm(path, timeout=timeout, max_seconds=None)
        else:
            temp_path = _convert_to_wav_mono_16k(path, timeout)
        with open(temp_path, "rb") as f:
            body = f.read()
    finally:
        if temp_path:
            try:
                os.unlink(temp_path)
            except Exception:
                pass

    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "audio/wav",
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
        msg = f"Pulse HTTP {getattr(e, 'code', '?')}: {getattr(e, 'reason', '')}".strip()
        if body:
            msg += f" — {body.strip()}"
        raise RuntimeError(msg) from e

    data = json.loads(payload)
    if not isinstance(data, dict):
        raise RuntimeError("Pulse returned invalid JSON")
    if data.get("error"):
        raise RuntimeError(str(data.get("error")))

    per_speaker_seconds: Dict[str, float] = {}
    per_speaker_text: Dict[str, str] = {}
    per_speaker_intervals: Dict[str, List[Tuple[float, float]]] = {}
    all_intervals: List[Tuple[float, float]] = []

    utterances = data.get("utterances")
    if isinstance(utterances, list) and len(utterances) > 0:
        for u in utterances:
            if not isinstance(u, dict):
                continue
            speaker = u.get("speaker")
            start = float(u.get("start") or 0.0)
            end = float(u.get("end") or 0.0)
            start = max(start - max(0.0, intro_seconds), 0.0)
            end = max(end - max(0.0, intro_seconds), 0.0)
            if end <= start:
                continue
            key = f"speaker_{int(speaker) if speaker is not None else 0}"
            per_speaker_seconds[key] = per_speaker_seconds.get(key, 0.0) + (end - start)
            per_speaker_intervals.setdefault(key, []).append((start, end))
            all_intervals.append((start, end))
            t = str(u.get("text") or u.get("transcript") or "").strip()
            if t:
                per_speaker_text[key] = (per_speaker_text.get(key, "") + " " + t).strip()
    else:
        words: List[Any] = []
        if isinstance(data.get("words"), list):
            words = data.get("words") or []
        if not words:
            channels = data.get("channels") or []
            if isinstance(channels, list) and len(channels) > 0:
                try:
                    words = channels[0]["alternatives"][0]["words"]
                except Exception:
                    words = []

        if not isinstance(words, list) or len(words) == 0:
            transcript = str(data.get("transcript") or "").strip()
            if transcript:
                return {}, 0, {"speaker_0": transcript}, {}, 0.0
            return {}, 0, {}, {}, 0.0

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
            per_speaker_intervals.setdefault(key, []).append((start, end))
            all_intervals.append((start, end))
            t = str(w.get("punctuated_word") or w.get("word") or "").strip()
            if t:
                per_speaker_text[key] = (per_speaker_text.get(key, "") + " " + t).strip()

    speaker_seconds_int = {k: max(1, int(round(v))) for k, v in per_speaker_seconds.items() if v > 0.0}
    overlap_seconds = _compute_overlap_seconds(all_intervals)
    inferred_duration = None
    if len(all_intervals) > 0:
        inferred_duration = max(e for _s, e in all_intervals)
    return speaker_seconds_int, overlap_seconds, per_speaker_text, per_speaker_intervals, inferred_duration


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--file", required=True)
    parser.add_argument("--intro-seconds", type=float, default=0.0)
    parser.add_argument("--timeout", type=int, default=60)
    parser.add_argument("--deepgram-key", type=str, default="")
    parser.add_argument("--mode", type=str, default="meeting")
    parser.add_argument("--max-seconds", type=float, default=0.0)
    args = parser.parse_args()

    path = args.file
    if not os.path.exists(path):
        print(json.dumps({"error": "file_not_found"}))
        return 2

    try:
        provider = (os.getenv("MEETING_STT_PROVIDER", "deepgram") or "deepgram").strip().lower()
        pulse_key = (os.getenv("PULSE_API_KEY", "")).strip()
        deepgram_key = (str(args.deepgram_key or "")).strip() or os.getenv("DEEPGRAM_API_KEY", "").strip()
        stt_enabled = (provider == "pulse" and pulse_key != "") or (provider != "pulse" and deepgram_key != "")

        # Prefer ffprobe for duration, but allow STT-only mode when ffprobe isn't available.
        total_after_intro = None
        ffprobe_error = None
        try:
            total = ffprobe_duration_seconds(path, timeout=args.timeout)
            total_after_intro = analyzed_window(total, args.intro_seconds)
        except Exception as e:
            ffprobe_error = e

        max_s = float(args.max_seconds or 0.0)
        max_s = None if max_s <= 0 else max(1.0, min(60.0, max_s))

        try:
            speakers, overlap, speaker_text, speaker_intervals, inferred_duration = run_diarization(
                path,
                args.intro_seconds,
                args.timeout,
                deepgram_key_override=deepgram_key,
                mode=str(args.mode or "meeting"),
                max_seconds=max_s,
            )
        except Exception:
            if stt_enabled:
                raise
            if total_after_intro is None:
                # No duration + no diarization means we can't produce anything useful.
                raise ffprobe_error or RuntimeError("No diarization provider configured")
            # Fallback (no provider): we can still provide real total_seconds, but no speaker split.
            speakers = {"Unknown": total_after_intro}
            overlap = 0
            speaker_text = {}
            speaker_intervals = {}
            inferred_duration = float(total_after_intro)

        if total_after_intro is None:
            if inferred_duration is None:
                raise ffprobe_error or RuntimeError("Unable to infer duration")
            total_after_intro = analyzed_window(inferred_duration, 0.0)

        speaker_embeddings: Dict[str, List[float]] = {}
        # Intro enrollment prioritizes fast name capture; skip per-speaker
        # embedding extraction here to reduce post-upload wait.
        is_intro_mode = str(args.mode or "").lower() == "intro"
        if (not is_intro_mode) and stt_enabled and isinstance(speaker_intervals, dict) and len(speaker_intervals) > 0:
            speaker_embeddings = _compute_speaker_embeddings(
                audio_path=path,
                speaker_intervals=speaker_intervals,
                intro_seconds=float(args.intro_seconds),
                timeout=int(args.timeout),
            )

        global_embedding = None
        # Intro enrollment: default to NO embedding so name enrollment feels instant.
        # Enable only if explicitly requested (slower due to SpeechBrain model + ffmpeg).
        want_intro_embed = (os.getenv("MEETING_INTRO_GLOBAL_EMBEDDING", "0").strip().lower() in ("1", "true", "yes", "y"))
        if str(args.mode or "").lower() == "intro" and want_intro_embed:
            global_embedding = _compute_global_embedding(
                audio_path=path,
                intro_seconds=float(args.intro_seconds),
                timeout=int(args.timeout),
                max_seconds=max_s,
            )

        out = {
            "total_seconds": int(total_after_intro),
            "speakers": {str(k): int(v) for k, v in speakers.items()},
            "overlap_seconds": int(overlap),
            "speaker_text": {str(k): str(v) for k, v in (speaker_text or {}).items()},
            "speaker_embeddings": speaker_embeddings,
            "global_embedding": global_embedding,
        }
        print(json.dumps(out))
        return 0
    except Exception as e:
        msg = str(e)
        # Helpful hint when ffprobe is missing and Deepgram key isn't present.
        if ("ffprobe" in msg or "No such file or directory: 'ffprobe'" in msg) and not stt_enabled:
            msg = msg + " (no STT keys: set DEEPGRAM_API_KEY or PULSE_API_KEY + MEETING_STT_PROVIDER=pulse)"
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

