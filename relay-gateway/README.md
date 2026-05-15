# WeChirp Node Deepgram Gateway

Production-grade **live STT path in Node.js**; **Laravel** keeps auth, database, voiceprints (SpeechBrain), and analytics.

## Architecture

```
Browser/Capacitor  --WS-->  Node gateway (:9200)  --WS-->  Deepgram
                              |
                              +--HTTP-->  Laravel /api/internal/relay/*
                                            (auth, persist stats, voice chunks)
```

| Layer | Responsibility |
|-------|----------------|
| **Node** | WebSocket server, Deepgram live, keepalive, reconnect, interim/final transcripts, talk-time aggregation |
| **Laravel** | Token validation, `ParticipantStat`, transcripts, ECAPA voiceprint jobs, Redis hot cache |

## Enable

`.env`:

```env
WECHIRP_RELAY_ENGINE=node
WC_RELAY_GATEWAY_PORT=9200
RELAY_INTERNAL_SECRET=your-long-random-secret
DEEPGRAM_API_KEY=...
LARAVEL_URL=http://127.0.0.1:9000
```

Generate secret:

```bash
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
```

## Run (development)

```bash
composer run dev:node
```

Or separately:

```bash
composer dev   # without php artisan deepgram:relay
npm run relay:gateway
```

Health:

```bash
curl http://127.0.0.1:9200/up
curl http://127.0.0.1:9200/metrics
```

## Client protocol

Same as PHP relay:

1. `POST /api/meetings/{id}/live-token`
2. `ws://host:9200/meetings/{id}/live?format=pcm16&transcript=1&auth=post`
3. First message: `{"type":"auth","token":"..."}`
4. Binary PCM16 16 kHz mono

Events: `auth.ok`, `stats.updated`, `transcript.updated`, `upstream_stt.disconnected`, `upstream_stt.reconnected`

## Cluster (scale-out)

For many concurrent meetings, run multiple Node gateways behind `cluster-proxy.mjs` (meeting-id hash) **or** multiple PHP relays — do not mix engines on the same port without a proxy.

See [`docs/relay-scaling.md`](../docs/relay-scaling.md).

## PHP relay

Set `WECHIRP_RELAY_ENGINE=php-amphp` (default) to use `php artisan deepgram:relay` on port 9001.
