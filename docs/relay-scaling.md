# WeChirp live relay — scaling guide

## Architecture tiers

| Tier | Setup | Concurrent lives (rough) |
|------|--------|---------------------------|
| Dev | `composer dev` (PHP relay :9001) | 1–10 |
| Dev (Node) | `composer run dev:node` (gateway :9200) | 10–80 |
| Single prod | 1 relay + nginx TLS | 10–40 |
| Cluster | 3+ relays + cluster proxy :9100 | 40–120 |
| Node gateway | `WECHIRP_RELAY_ENGINE=node` — see `relay-gateway/README.md` | 50–150+ |

## Single relay (default)

```bash
php artisan deepgram:relay --host=127.0.0.1 --port=9001
```

Health: `GET /up` · Metrics: `GET /metrics`

## Multi-relay cluster

1. Start workers:

```bash
bash scripts/run-relay-cluster.sh
```

2. Point clients at the proxy (meeting-id sticky routing):

```env
WC_RELAY_WS_URL=ws://127.0.0.1:9100
RELAY_CLUSTER_PORTS=9001,9011,9021
```

The Node proxy in `relay-gateway/cluster-proxy.mjs` hashes `meeting_id` to a backend port so all audio for one meeting stays on one PHP process (in-memory voiceprint state).

Production: put the proxy behind nginx with `proxy_read_timeout 3600s` (see `nginx-wechirp-relay.conf.example`).

## Client connection checklist

1. `POST /api/meetings/{id}/live-token` → short-lived token
2. WebSocket `…/meetings/{id}/live?format=pcm16&transcript=1&auth=post`
3. First message: `{"type":"auth","token":"…"}`
4. On disconnect: client auto-reconnects (5 attempts) then HTTP chunks fallback

## Redis hot state

When `MEETING_RELAY_REDIS_HOT_STATE=true`, relay writes:

- `meeting_relay_live:{id}` — stats snapshot
- `meeting_relay_transcript:{id}` — live caption lines

HTTP `GET /stats` and transcript SSE read cache first during live meetings.

## Operational env

See `.env.example` keys prefixed with `MEETING_RELAY_` and `MEETING_WS_`.
