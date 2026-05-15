# WeChirp production deployment

Use [`.env.production.example`](../.env.production.example) as the starting point. Stack overview: [`wechirp-stack.md`](wechirp-stack.md).

## 1. Server processes

Run these as separate systemd/supervisor programs (or Laravel Cloud / Forge daemons):

| Process | Command |
|---------|---------|
| HTTP | `php artisan serve` or php-fpm + nginx |
| Queue worker | `php artisan queue:work redis --queue=audio,default --tries=3 --timeout=0` |
| Deepgram relay | `php artisan deepgram:relay --host=127.0.0.1 --port=9001` |
| Scheduler | `* * * * * php /path/to/artisan schedule:run` |

Example worker script: [`scripts/production-queue-worker.sh`](../scripts/production-queue-worker.sh).

## 2. PostgreSQL

```bash
php artisan migrate --force
```

Use `DB_CONNECTION=pgsql` in production.

## 3. Redis

Set:

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

Ensure Redis is reachable before starting workers.

## 4. S3 meeting chunks

```env
WECHIRP_AUDIO_DISK=s3
WECHIRP_AUDIO_PREFIX=meeting_chunks
AWS_BUCKET=your-bucket
```

Intro and HTTP meeting chunks upload to S3. Jobs download each file to a temp path for ffmpeg/Python, then delete the temp file.

**IAM policy (minimal):** `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject` on `arn:aws:s3:::your-bucket/meeting_chunks/*`.

Live relay buffers (`meeting_live_ws/`) stay on the **local** disk for low latency.

### Cloudflare R2

```env
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=auto
AWS_BUCKET=wechirp-audio
AWS_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

## 5. Relay TLS + WebSocket proxy

Expose the relay behind nginx/Caddy:

```env
WC_RELAY_WS_URL=wss://api.yourdomain.com/ws-relay
```

Proxy WebSocket upgrades to `127.0.0.1:9001`. Full example: [`nginx-wechirp-relay.conf.example`](nginx-wechirp-relay.conf.example).

Relay tuning (optional):

```env
MEETING_RELAY_TOKEN_TTL_SECONDS=900
MEETING_RELAY_MAX_CONNECTIONS=100
MEETING_WS_PERSIST_INTERVAL_SECONDS=8
MEETING_RELAY_UPSTREAM_KEEPALIVE_SECONDS=4
MEETING_RELAY_REDIS_HOT_STATE=true
MEETING_RELAY_STICKY_SESSIONS=true
```

- **Metrics:** `GET http://127.0.0.1:9001/metrics` (Prometheus-style text)
- **Live tokens:** clients should `POST /api/meetings/{id}/live-token` before opening the relay WebSocket
- **Multiple relay workers:** use `npm run relay:cluster` or `bash scripts/run-relay-cluster.sh` (ports 9001,9011,9021 + proxy :9100). See [`relay-scaling.md`](relay-scaling.md).
- **Post-connect auth:** clients use `auth=post` and send `{"type":"auth","token"}` as the first WS frame (tokens not in URLs).

## 6. Capacitor / mobile

```env
APP_URL=https://api.yourdomain.com
CAPACITOR_SERVER_URL=https://api.yourdomain.com/app
```

Physical devices cannot use `127.0.0.1`.

## 7. Voiceprint ML

On the app server once:

```bash
bash scripts/setup-voiceprint.sh
php artisan voiceprint:verify
```

## 8. Health check

Authenticated: `GET /api/meetings/services/health` — confirms Deepgram key, relay `/up`, OpenAI, Redis queue depth, and `audio_disk`.
