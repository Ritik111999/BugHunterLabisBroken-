#!/usr/bin/env bash
# Production queue worker for WeChirp (intro + meeting chunks on "audio", summaries on "default").
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

AUDIO_QUEUE="${WECHIRP_QUEUE_AUDIO:-audio}"
DEFAULT_QUEUE="${WECHIRP_QUEUE_DEFAULT:-default}"
CONNECTION="${QUEUE_CONNECTION:-redis}"

echo "WeChirp queue worker — connection=${CONNECTION} queues=${AUDIO_QUEUE},${DEFAULT_QUEUE}"
exec php artisan queue:work "${CONNECTION}" \
  --queue="${AUDIO_QUEUE},${DEFAULT_QUEUE}" \
  --tries=3 \
  --timeout=0 \
  --sleep=1 \
  --max-time=3600
