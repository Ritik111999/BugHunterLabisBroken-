## WeChirp Audio Realtime Analytics (Deepgram)

This document explains:
- **How realtime speaker % + crosstalk works**
- **Which APIs exist**
- **How to test everything (HTTP chunks + WebSocket live)**
- **Why you may still see `speaker_0`**

---

## Concepts

### Speaker labels vs real names

Deepgram diarization returns **speaker labels** like:
- `speaker_0`
- `speaker_1`

Those labels are **not real names**. We convert them to real names using:
- **Intro enrollment phrase**: “my name is Rajat / I am Rajat / this is Rajat”
- Stored mapping in DB:
  - `speaker_mappings.speaker_label` → `meeting_participants.id`

### Voice recognition (voiceprints)

If `scripts/analyze_audio.py` can load SpeechBrain (via `scripts/.venv`) it will also emit
`speaker_embeddings` (voiceprints). During meeting processing we match those voiceprints
against enrolled participants' stored `voice_embedding.voiceprint` to recognize who is talking
without requiring "my name is X" during the meeting.

### Crosstalk

Crosstalk is computed as:
\[
\text{crosstalk %} = \frac{\text{overlap seconds}}{\text{total seconds}} \times 100
\]

Deepgram provides timings (words/utterances), and we compute overlaps.

---

## Two supported ingestion modes

### 1) HTTP chunks (simple test)

- `POST /api/audio/chunk` (multipart form-data)
- Worker job: `ProcessChunkJob`
- Analyzer: `scripts/analyze_audio.py`
  - uses Deepgram prerecorded `listen` API when `DEEPGRAM_API_KEY` is set
  - computes speaker voiceprints when SpeechBrain is available (recommended: use `scripts/.venv`)

**DB updates**
- `meeting_participants` (placeholder `Speaker N`, renamed when intro detected)
- `speaker_mappings`
- `participant_stats`
- `meeting_analytics`

### 2) WebSocket live relay (best realtime)

- Start relay:

```bash
php artisan deepgram:relay --host=127.0.0.1 --port=8089
```

- Frontend connects:
`ws://127.0.0.1:8089/meetings/{meetingId}/live?token={SANCTUM_TOKEN}`

- Client sends **binary audio frames**.
- Relay forwards to **Deepgram Live** and updates DB continuously.
- Relay also computes **voiceprints** from incoming MediaRecorder chunks (SpeechBrain) and
  matches live `speaker_0`/`speaker_1` to enrolled participants when confident.

---

## APIs

All protected routes require:
- `Authorization: Bearer <token>`

### Meetings
- `POST /api/meetings` → create meeting (returns `id`)

### HTTP chunk ingestion
- `POST /api/audio/chunk`
  - form-data:
    - `meeting_id` (int)
    - `chunk_index` (int)
    - `audio` (file)

### Stats (DB-backed)
- `GET /api/meetings/{id}/stats` → snapshot
- `GET /api/meetings/{id}/stats/stream` → SSE realtime stream from DB polling

### Speaker mapping
- `GET /api/meetings/{id}/speakers`
- `POST /api/meetings/{id}/speakers/map`
  - JSON:
    - `speaker_label` (e.g. `speaker_0`)
    - `name` (e.g. `Rajat`) or `participant_id`

---

## Testing

### A) Test HTTP chunks (Postman)
1. Login → get token
2. Create meeting → get meetingId
3. Send audio:
   - `POST /api/audio/chunk`
   - use an MP3 containing intro: “my name is Rajat”
4. Check:
   - `GET /api/meetings/{id}/speakers`
   - `GET /api/meetings/{id}/stats`

If intro phrase is detected, `meeting_participants.name` will change from `Speaker 0` → `Rajat`.

### B) Test WebSocket MP3 stream (recommended)
1. Start relay
2. Send MP3 via Node:

```bash
node scripts/send_mp3_ws.mjs "ws://127.0.0.1:8089/meetings/MEETING_ID/live?token=TOKEN" "/path/to/file.mp3"
```

3. Check stats:
- `GET /api/meetings/{id}/stats`

---

## Troubleshooting

### “Still shows speaker_0”
Common causes:
- The intro phrase wasn’t clearly transcribed (noise, music, multiple speakers).
- The phrase doesn’t match patterns. Supported patterns include:
  - “my name is X”
  - “i am X”
  - “i’m X”
  - “this is X”
- Deepgram returned words but without speaker diarization (rare; usually audio format issue).

### “No updates / DB empty”
- Queue worker not running (HTTP chunks mode):
  - `php artisan queue:work --queue=audio`
- Wrong `meeting_id` (must exist in `meetings` table).

