## WeChirp Postman Collection

- Import `WeChirp_API.postman_collection.json` into Postman.
- Set `baseUrl` to your backend URL (default `http://127.0.0.1:8000`).
- Run **Auth - Login** to populate `token`.
- Run **Meetings - Create** to populate `meetingId`.

### Realtime (API-only) speaker stats

This project supports two realtime paths:

1) **HTTP chunks + queue**
- Send `POST /api/audio/chunk` with form-data `audio` file.
- Subscribe `GET /api/meetings/{id}/stats/stream` (SSE) to see updates.

2) **Deepgram Live relay (best realtime)**
- Start relay server:

```bash
php artisan deepgram:relay --host=127.0.0.1 --port=8081
```

- Connect frontend (or Postman websocket) to:
`ws://127.0.0.1:8081/meetings/{meetingId}/live?token={SANCTUM_TOKEN}`
- Stream binary audio frames to that websocket.
- Read the latest DB snapshot:
`GET /api/meetings/{id}/stats`

