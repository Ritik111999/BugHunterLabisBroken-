<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>WeChirp</title>
    @php
        $sttProvider = strtolower((string) env('MEETING_STT_PROVIDER', 'deepgram'));
        $wechirpBoot = [
            'relayWsUrl' => (string) config('services.wechirp.relay_ws_url'),
            'sttProvider' => $sttProvider,
            'sttConfigured' => $sttProvider === 'pulse'
                ? trim((string) (config('services.pulse.api_key') ?: env('PULSE_API_KEY', ''))) !== ''
                : trim((string) (config('services.deepgram.api_key') ?: env('DEEPGRAM_API_KEY', ''))) !== '',
            'voiceprintReady' => \App\Support\MlPythonEnv::isVoiceprintReady(),
            'appEnv' => (string) config('app.env'),
            'consumerMode' => filter_var(env('WECHIRP_CONSUMER_MODE', true), FILTER_VALIDATE_BOOLEAN),
            'showDevTools' => filter_var(env('WECHIRP_SHOW_DEV_TOOLS', false), FILTER_VALIDATE_BOOLEAN),
            'studioDebugPanels' => filter_var(env('WECHIRP_STUDIO_DEBUG_PANELS', env('WECHIRP_SHOW_DEV_TOOLS', false)), FILTER_VALIDATE_BOOLEAN),
        ];
    @endphp
    <script>
        window.__WECHIRP_BOOT__ = @json($wechirpBoot);
    </script>
    @include('partials.pwa-head', ['pwaAppleTitle' => 'WeChirp'])
    @vite(['resources/css/wechirp-app.css', 'resources/js/wechirp-app/main.jsx'])
</head>
<body class="m-0 min-h-[100dvh] touch-pan-y antialiased text-slate-900">
    <div id="root"></div>
    @include('partials.pwa-register')
</body>
</html>
