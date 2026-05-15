<meta name="theme-color" content="{{ $pwaThemeColor ?? '#0f172a' }}" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
<meta name="apple-mobile-web-app-title" content="{{ $pwaAppleTitle ?? config('app.name', 'WeChirp') }}" />
<link rel="manifest" href="{{ route('pwa.manifest') }}" />
<link rel="apple-touch-icon" href="{{ url('/pwa/icon-192.png') }}" />
