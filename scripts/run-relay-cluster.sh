#!/usr/bin/env bash
# Start multiple PHP Deepgram relays + optional Node cluster proxy (meeting-id sticky routing).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PORTS="${RELAY_CLUSTER_PORTS:-9001,9011,9021}"
IFS=',' read -ra PORT_ARR <<< "$PORTS"

PIDS=()
cleanup() {
  for pid in "${PIDS[@]}"; do
    kill "$pid" 2>/dev/null || true
  done
}
trap cleanup EXIT INT TERM

for port in "${PORT_ARR[@]}"; do
  port="$(echo "$port" | tr -d ' ')"
  echo "Starting relay on 127.0.0.1:${port}"
  php artisan deepgram:relay --host=127.0.0.1 --port="$port" &
  PIDS+=($!)
done

PROXY_PORT="${RELAY_CLUSTER_PORT:-9100}"
if [[ "${START_RELAY_CLUSTER_PROXY:-1}" == "1" ]]; then
  echo "Starting cluster proxy on 127.0.0.1:${PROXY_PORT}"
  RELAY_CLUSTER_PORTS="$PORTS" RELAY_CLUSTER_PORT="$PROXY_PORT" node relay-gateway/cluster-proxy.mjs &
  PIDS+=($!)
  echo "Set WC_RELAY_WS_URL=ws://127.0.0.1:${PROXY_PORT}"
fi

echo "Relay cluster running (${#PIDS[@]} processes). Ctrl+C to stop."
wait
