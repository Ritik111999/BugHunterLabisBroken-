# WeChirp (Meet)

Laravel backend (REST API, admin, meeting pipeline), Vite + Tailwind for web UI, optional **Capacitor** iOS/Android shells. Realtime meetings use a small **PHP WebSocket relay** (`php artisan deepgram:relay`) alongside `php artisan serve`.

**This repository does not use Docker.** Run PHP, Node, and (for mobile) Xcode/Android Studio directly on your machine.

## Requirements

- **PHP** 8.3+ with common extensions (`pdo_sqlite` or `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`)
- **Composer** 2.x  
- **Node.js** 20+ and **npm**  
- **SQLite** (default in `.env.example`) or MySQL/Postgres if you change `DB_*`  
- **Mobile (optional):** Xcode (iOS), Android Studio (Android) — see [`mobile/README.md`](mobile/README.md)

## First-time setup (local)

From the repository root:

```bash
composer run setup
```

That installs PHP and JS dependencies, creates `.env` from `.env.example` if missing, generates `APP_KEY`, ensures `database/database.sqlite` exists, runs migrations, and builds frontend assets.

Then edit `.env`: set `APP_NAME`, `APP_URL`, and at minimum `DEEPGRAM_API_KEY` if you use live/batch STT features.

First-time **Capacitor** dependencies:

```bash
cd mobile && npm install && npx cap sync
```

## Daily development

```bash
composer run dev
```

Starts together: **HTTP server** (port **9000**), **queue worker** (`audio` + `default` queues for intro/meeting chunks), **Pail** logs, **Vite** (port **9002**), and the **Deepgram relay** (WebSocket on port **9001**). Stop the terminal with **Ctrl+C** when you are done (if the relay fails because port 9001 is already in use, the other processes keep running—stop the old relay or pick another port).

- Web: [http://127.0.0.1:9000](http://127.0.0.1:9000) — **consumer app (React):** [http://127.0.0.1:9000/app](http://127.0.0.1:9000/app) — meeting tools / studio: [http://127.0.0.1:9000/demo](http://127.0.0.1:9000/demo)  
- Admin sign-in is the default `/` redirect.

Minimal stack (separate terminals or tabs) if you prefer not to use `concurrently`:

```bash
php artisan serve --host=127.0.0.1 --port=9000
php artisan queue:listen --tries=1 --timeout=0 --queue=audio,default
php artisan deepgram:relay --host=127.0.0.1 --port=9001
```

Use `npm run dev` when changing Vite/Tailwind sources.

### iOS Simulator (Capacitor)

Boot a simulator in **Simulator.app**, then from the repo root:

```bash
npm run dev:ios
```

That runs `npm run build`, starts Laravel on **9000** and a queue worker if needed, syncs `mobile/ios`, and deploys to the booted device. The script keeps running until **Ctrl+C** (so PHP + queue stay up while you test). Equivalent: `composer run dev:ios`.

## Technology stack

WeChirp is built on a fixed product stack: **Deepgram** (live + batch STT), **OpenAI** (structured meeting summaries + semantic search), **SpeechBrain** voiceprints (Python), **Laravel** API + Amp relay, **React + Capacitor** client. See [`docs/wechirp-stack.md`](docs/wechirp-stack.md) and [`config/wechirp.php`](config/wechirp.php). Production: [`.env.production.example`](.env.production.example) and [`docs/wechirp-production.md`](docs/wechirp-production.md).

## Useful docs in-repo

- [`docs/wechirp-stack.md`](docs/wechirp-stack.md) — official providers, data flow, production checklist  
- [`mobile/README.md`](mobile/README.md) — Capacitor URLs, simulator vs device, `server.url`  
- [`postman/README.md`](postman/README.md) — API + relay WebSocket  
- [`docs/audio-realtime.md`](docs/audio-realtime.md) — realtime audio path  

## Tests

```bash
composer run test
```

## License

MIT. Laravel components remain under the [Laravel license](https://opensource.org/licenses/MIT).
