# WeChirp mobile (Capacitor)

Native **iOS** and **Android** projects that open your existing Laravel + Vite app inside a **WebView**, pointed at your server URL. Your PHP API and Blade/admin UI keep running on the backend; the apps are the store-ready shell.

## Prerequisites

- **Backend running** (from repo root): `composer run dev` or `php artisan serve` on port **9000**
- **Physical iPhone / iPad on Wi‑Fi:** Laravel must listen on all interfaces, not only loopback:  
  `php artisan serve --host=0.0.0.0 --port=9000`
  Then set `server.url` in `capacitor.config.js` to `http://<YOUR_MAC_LAN_IP>:9000` and run `npx cap sync ios`.
- **Xcode** (Mac) for iOS, **Android Studio** for Android
- **CocoaPods** (for iOS): `brew install cocoapods` (first Xcode open may run `pod install`)

## Connect backend and mobile (dev)

1. **Repo root**: copy `.env.example` → `.env`, run migrations, then start Laravel (and Vite if you use `composer run dev`):
   ```bash
   composer run dev
   ```
   Minimal API-only: `php artisan serve --port=9000`
2. **Same host everywhere**: if `mobile/capacitor.config.js` uses `http://127.0.0.1:9000/...`, set **`APP_URL=http://127.0.0.1:9000`** in `.env` so Blade `url()` / cookies match the WebView. For `localhost` in Capacitor, use `APP_URL=http://localhost:9000` instead.
3. **Live meeting WebSocket**: `composer run dev` from the repo root already starts **`php artisan deepgram:relay`** on port **9001**. If you only run `php artisan serve`, start the relay in another terminal: `php artisan deepgram:relay --host=127.0.0.1 --port=9001` (or set **`WC_RELAY_WS_URL`** in `.env` to match your relay).
4. **Sync native projects** after changing `server.url` or Capacitor files:
   ```bash
   cd mobile && npx cap sync
   ```
5. Run the app (Xcode, Android Studio, or `npx cap run ios --list` / `--target <id>`).

**Blank screen in the simulator?** (1) **Laravel must be running** on the same host/port as `mobile/capacitor.config.js` `server.url` (from `APP_URL` / `CAPACITOR_SERVER_URL`, default `http://127.0.0.1:9000/app`). If nothing listens, the WebView stays white. (2) Prefer **`127.0.0.1` not `localhost`** in `.env` `APP_URL` for simulator builds — the Capacitor config normalizes `localhost` → `127.0.0.1`, and Laravel forces the asset root from the request when it sees the **`WeChirpCapacitorShell`** user-agent token so `@vite` URLs match the page. (3) If `composer run dev` is running, Laravel may serve `/app` with **Vite dev** script URLs (`http://127.0.0.1:5173`). The simulator WebView often cannot load that host. Laravel then serves **`/build` manifest assets** for that UA. Run **`npm run build`** so `public/build` exists, then reload the app. Optional: stop Vite (remove `public/hot`) while testing only `php artisan serve`.

## Voice intro in the iOS Simulator (mic / STT)

The Simulator often records **non-speech** (loopback / noise) at normal levels, so **wired earphones do not reroute** WebView audio the way they do on a **physical iPhone**.

1. From the **repo root**, run: `npm run sim:ios-voice-setup` (or `bash scripts/simulator-voice-intro-setup.sh`). This opens **System Settings → Sound** (or the Sound preference pane) and **Simulator**, prints steps, and may auto-select **Simulator → I/O → Audio Input → Mac Microphone** (English UI only; grant **Automation** if macOS asks).

   If you see **`npm error ENOENT ... package.json`**, you ran the command from your home directory — `cd` into this repo first (the folder that contains `package.json`), or run the script with a full path, e.g. `bash /path/to/Meet/scripts/simulator-voice-intro-setup.sh`.
2. Match **macOS input** to the same microphone and confirm the **input level meter** moves when you speak toward the Mac.
3. In Studio, tap **Try again** and say **"My name is …"** clearly.

If you already added participant names on the meeting screen but the **simulator still returns no speech text**, use Studio’s **“Attach recording to participant”** control (HTTP enroll) so the server can still attach a voiceprint to that row when `APP_ENV=local` or `MEETING_INTRO_ALLOW_MANUAL_BIND=true`.

For reliable enrollment, use **Run on a physical iPhone** (section below).

The meeting demo page defaults **Base URL** to the page origin (Capacitor + `server.url`), then `APP_URL`, so API calls hit the same Laravel instance without manual typing.

## Which URL to use (`capacitor.config.js` → `server`)

**Capacitor config format:** `mobile/capacitor.config.js` must use **`module.exports = { … }`** (CommonJS). Using **`export default`** together with **`"type": "module"`** in `mobile/package.json` breaks sync: the CLI loads the file with `require()` and the generated native `capacitor.config.json` nests your config under `default`, so **`server.url` is ignored** and the WebView only shows the placeholder `www/index.html`.

This repo sets **`server.url`** to the **WeChirp app** (`/app`) while you develop locally. Remove the `server` block for a fully bundled build into `www/`.

To point the WebView at a different route or host, edit:

```json
"server": {
  "url": "http://…",
  "cleartext": true
}
```

| Target | Typical `server.url` |
|--------|----------------------|
| **iOS Simulator** | `http://127.0.0.1:9000/app` (with `php artisan serve` on the Mac) |
| **Android Emulator** | `http://10.0.2.2:9000` (host machine from emulator) |
| **Physical phone** (USB/Wi‑Fi) | Your Mac’s LAN IP, e.g. `http://192.168.1.10:9000` |
| **Production** | `https://your-domain.com` (remove `cleartext` or keep only for dev builds) |

If you had `server.url` set but nothing is listening on that port, the WebView can stay **blank white**—start the backend or remove the `server` block to use bundled `www/` again.

After changing the URL:

```bash
cd mobile
npx cap sync
```

## Run on a physical iPhone (USB)

1. **Same Wi‑Fi** as your Mac (USB is for install/debug; the app still loads the server over the network).
2. **Laravel** (repo root): `php artisan serve --host=0.0.0.0 --port=9000` so the phone can reach your Mac’s LAN IP.
3. **`mobile/capacitor.config.js`** → `server.url` = `http://<Mac LAN IP>:9000` (currently set for this machine; change if your IP changes), then `cd mobile && npx cap sync ios`.
4. **Xcode**: open `mobile/ios/App/App.xcworkspace` → top bar: select your **iPhone** → **Signing & Capabilities**: choose your **Team** (Apple ID).
5. **Run** (▶). On the iPhone: **Settings → General → VPN & Device Management** → trust your developer app if prompted.

## Daily commands

```bash
cd mobile
npm install          # first time
npx cap sync         # after URL or www/ changes
npm run open:ios     # open Xcode → pick simulator → Run
npm run open:android # open Android Studio → Run on emulator/device
```

## App Store & Play Store checklist (high level)

1. **Bundle / application ID**: `com.wechirp.app` in `capacitor.config.js` — change if you need a unique id for the stores.
2. **Production server**: set `server.url` to your **HTTPS** API/site; avoid cleartext in release builds.
3. **Icons & splash**: add assets via [Capacitor assets](https://capacitorjs.com/docs/guides/splash-screens-and-icons) before submission.
4. **Signing**: configure in **Xcode** (Apple) and **Android Studio** / Play Console (upload key, App Signing).
5. **Privacy / permissions**: only add native plugins (camera, mic, etc.) when you need them; WebView + HTTPS is the baseline.

## Native iOS app (not Safari)

The Capacitor **App** is a real **`.app`** with a **WKWebView** inside. **Safari** was only a temporary workaround when Xcode could not install the build.

If **`npx cap run ios`** or Xcode **Run** says there is **no simulator destination** or **iOS 26.5 is not installed**:

1. Your **Xcode 26.5** toolchain expects the **iOS 26.5 Simulator** runtime to match `iphonesimulator26.5.sdk`.
2. Install it: **Xcode → Settings → Platforms** (or **Components**) → download **iOS 26.5 Simulator** (wording may vary slightly).
3. Open **`mobile/ios/App/App.xcworkspace`**, select an **iPhone** simulator, press **Run**.

This repo now includes a **shared** `App.xcscheme` and **`SUPPORTED_PLATFORMS = iphoneos iphonesimulator`** so the scheme can target simulators once that runtime exists.

## Notes

- This is a **hybrid** shell (WebView), not a rewritten React Native app. It matches “ship quickly” while your team keeps one Laravel codebase.
- For a **fully native** UI later, you’d consume the same `/api/*` routes from Swift/Kotlin or Flutter; that is a separate, larger project.
