"""Shared ML cache paths under storage/app (writable, no ~/.cache permission issues)."""

from __future__ import annotations

import os
from pathlib import Path


def repo_root() -> Path:
    return Path(__file__).resolve().parent.parent


def configure_ml_caches() -> Path:
    """
    Point HuggingFace / SpeechBrain / Torch caches into the Laravel storage tree.
    Returns the ECAPA savedir used by EncoderClassifier.
    """
    root = repo_root()
    base = root / "storage" / "app" / "speechbrain_models"
    base.mkdir(parents=True, exist_ok=True)
    ecapa = base / "ecapa"
    ecapa.mkdir(parents=True, exist_ok=True)
    hf_home = base / "huggingface"
    hf_home.mkdir(parents=True, exist_ok=True)
    hub = hf_home / "hub"
    hub.mkdir(parents=True, exist_ok=True)
    torch_home = base / "torch"
    torch_home.mkdir(parents=True, exist_ok=True)

    os.environ.setdefault("SPEECHBRAIN_CACHE", str(ecapa))
    os.environ.setdefault("HF_HOME", str(hf_home))
    os.environ.setdefault("HF_HUB_CACHE", str(hub))
    os.environ.setdefault("TORCH_HOME", str(torch_home))
    os.environ.setdefault("XDG_CACHE_HOME", str(base))

    return ecapa


def load_ecapa_classifier():
    """Load (or return cached) SpeechBrain ECAPA EncoderClassifier."""
    import contextlib
    import sys

    import torch  # type: ignore
    from speechbrain.inference.speaker import EncoderClassifier  # type: ignore

    savedir = configure_ml_caches()
    with contextlib.redirect_stdout(sys.stderr):
        return EncoderClassifier.from_hparams(
            source="speechbrain/spkrec-ecapa-voxceleb",
            savedir=str(savedir),
            run_opts={"device": "cpu"},
        )
