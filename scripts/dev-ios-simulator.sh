#!/usr/bin/env bash
# Build SPA assets, ensure Laravel answers on 127.0.0.1:9000, run a queue worker for the
# "audio" queue (intro + meeting chunks), sync Capacitor iOS, then deploy to the booted simulator.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "==> npm run build (Capacitor loads /public/build via native WebView middleware)"
npm run build

BOOTED_LINE="$(xcrun simctl list devices booted 2>/dev/null | grep -E 'iPhone|iPad' | head -1 || true)"
if [[ -z "${BOOTED_LINE}" ]]; then
  echo "No booted iOS simulator. Open Simulator.app and boot a device, then re-run this script." >&2
  exit 1
fi
# UDID is second (...) on the line
UDID="$(echo "${BOOTED_LINE}" | sed -n 's/.*(\([A-F0-9-]*\)).*/\1/p')"
if [[ -z "${UDID}" ]]; then
  echo "Could not parse simulator UDID from: ${BOOTED_LINE}" >&2
  exit 1
fi
echo "==> Booted simulator: ${UDID}"

SERVE_PID=""
QUEUE_PID=""
RELAY_PID=""
RELAY_PORT="9001"
if [[ -f "${ROOT}/.env" ]]; then
  _rp="$(grep -E '^WC_RELAY_PORT=' "${ROOT}/.env" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d ' ')"
  [[ -n "${_rp}" ]] && RELAY_PORT="${_rp}"
fi

cleanup() {
  if [[ -n "${RELAY_PID}" ]]; then kill "${RELAY_PID}" 2>/dev/null || true; fi
  if [[ -n "${QUEUE_PID}" ]]; then kill "${QUEUE_PID}" 2>/dev/null || true; fi
  if [[ -n "${SERVE_PID}" ]]; then kill "${SERVE_PID}" 2>/dev/null || true; fi
}
trap cleanup EXIT INT TERM

php artisan view:clear >/dev/null 2>&1 || true

laravel_listen_pids() {
  lsof -nP -iTCP:9000 -sTCP:LISTEN -t 2>/dev/null | tr '\n' ' ' || true
}

free_port_if_stale() {
  local port="$1"
  local pids
  pids="$(lsof -nP -iTCP:"${port}" -sTCP:LISTEN -t 2>/dev/null | tr '\n' ' ' || true)"
  if [[ -n "${pids// /}" ]]; then
    echo "==> Port ${port} is in use (PID(s): ${pids}) but health check failed — stopping stale process(es)."
    # shellcheck disable=SC2086
    kill ${pids} 2>/dev/null || true
    sleep 1
  fi
}

if curl -sf --max-time 2 "http://127.0.0.1:9000/up" >/dev/null 2>&1; then
  echo "==> Laravel already reachable at http://127.0.0.1:9000 (not starting a second php artisan serve)."
else
  if [[ -n "$(laravel_listen_pids)" ]]; then
    free_port_if_stale 9000
  fi
  echo "==> Starting php artisan serve on 127.0.0.1:9000 …"
  php artisan serve --host=127.0.0.1 --port=9000 &
  SERVE_PID=$!
  for _ in $(seq 1 60); do
    if curl -sf --max-time 2 "http://127.0.0.1:9000/up" >/dev/null 2>&1; then
      break
    fi
    sleep 0.5
  done
  if ! curl -sf --max-time 2 "http://127.0.0.1:9000/up" >/dev/null 2>&1; then
    echo "Laravel did not become ready on port 9000." >&2
    echo "Try: kill \$(lsof -t -iTCP:9000 -sTCP:LISTEN) && npm run dev:ios" >&2
    exit 1
  fi
fi

# Jobs from IntroChunkController / AudioChunkController use onQueue('audio').
if [[ "${SKIP_QUEUE_WORKER:-0}" != "1" ]]; then
  echo "==> Starting queue worker (audio,default) — set SKIP_QUEUE_WORKER=1 if you already run queue:listen."
  php artisan queue:listen --tries=1 --timeout=0 --queue=audio,default &
  QUEUE_PID=$!
fi

# Live meeting STT uses a separate WebSocket relay (not Laravel HTTP). Without it, the app shows WS code 1006.
if curl -sf --max-time 2 "http://127.0.0.1:${RELAY_PORT}/up" >/dev/null 2>&1; then
  echo "==> Deepgram relay already reachable at ws://127.0.0.1:${RELAY_PORT}"
else
  if lsof -nP -iTCP:"${RELAY_PORT}" -sTCP:LISTEN -t >/dev/null 2>&1; then
    free_port_if_stale "${RELAY_PORT}"
  fi
  echo "==> Starting Deepgram relay for live transcription on ws://127.0.0.1:${RELAY_PORT} …"
  php artisan deepgram:relay --host=127.0.0.1 --port="${RELAY_PORT}" &
  RELAY_PID=$!
  for _ in $(seq 1 60); do
    if curl -sf --max-time 2 "http://127.0.0.1:${RELAY_PORT}/up" >/dev/null 2>&1; then
      break
    fi
    sleep 0.5
  done
  if ! curl -sf --max-time 2 "http://127.0.0.1:${RELAY_PORT}/up" >/dev/null 2>&1; then
    echo "WARNING: Deepgram relay did not start on port ${RELAY_PORT}. Live WebSocket STT will fail until you run: php artisan deepgram:relay" >&2
  fi
fi

echo "==> cap sync ios && cap run ios"
cd "${ROOT}/mobile"
npx cap sync ios
npx cap run ios --target "${UDID}"

if [[ -n "${SERVE_PID}" || -n "${QUEUE_PID}" || -n "${RELAY_PID}" ]]; then
  echo "==> Simulator updated. This script started Laravel, queue, and/or relay — press Ctrl+C to stop them."
  wait || true
fi

echo "Stopped."
