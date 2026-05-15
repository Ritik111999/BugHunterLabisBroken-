#!/usr/bin/env bash
# Install SpeechBrain + PyTorch into scripts/.venv for voice fingerprint enrollment.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}/scripts"

echo "==> Creating Python venv at scripts/.venv"
if [[ ! -x ".venv/bin/python" ]]; then
  python3 -m venv .venv
fi

echo "==> Installing dependencies (torch, speechbrain, numpy) — may take a few minutes"
.venv/bin/pip install -U pip wheel
.venv/bin/pip install -r requirements-speechbrain.txt

mkdir -p "${ROOT}/storage/app/speechbrain_models/ecapa"
mkdir -p "${ROOT}/storage/app/speechbrain_models/huggingface/hub"

echo "==> Downloading ECAPA model + running test embed (first run downloads ~100MB)"
export HF_HOME="${ROOT}/storage/app/speechbrain_models/huggingface"
export SPEECHBRAIN_CACHE="${ROOT}/storage/app/speechbrain_models/ecapa"
export HF_HUB_CACHE="${HF_HOME}/hub"
export TORCH_HOME="${ROOT}/storage/app/speechbrain_models/torch"

VERIFY_OUT="$(.venv/bin/python "${ROOT}/scripts/verify_voiceprint.py" 2>&1)" || true
echo "${VERIFY_OUT}"

if echo "${VERIFY_OUT}" | grep -q '"ok": true'; then
  echo ""
  echo "✅ Voice fingerprint ready."
  echo ""
  echo "Laravel auto-uses scripts/.venv/bin/python when MEETING_ANALYZER_PYTHON is empty."
  echo "Optional .env override:"
  echo "MEETING_ANALYZER_PYTHON=${ROOT}/scripts/.venv/bin/python"
  echo ""
  echo "Restart php artisan serve / npm run dev:ios, then re-run voice intro in the app."
  exit 0
fi

echo ""
echo "❌ Voiceprint verify failed. See JSON above." >&2
echo "Requirements: ffmpeg on PATH (brew install ffmpeg)." >&2
exit 1
