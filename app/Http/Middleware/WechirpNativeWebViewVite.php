<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capacitor WKWebView cannot load JS/CSS from the Vite dev server (e.g. 127.0.0.1:9002) while the
 * HTML comes from another origin/port. When the shell appends {@see config('services.wechirp.native_shell_ua_token')}
 * to the user agent (see mobile/capacitor.config.json appendUserAgent), point Vite at a non-existent
 * "hot" file so @vite serves hashed assets from public/build instead.
 *
 * Also forces the app URL root to the current request host (e.g. 127.0.0.1:9000) so @vite / route()
 * never emit http://localhost:9000/... while the WebView loaded http://127.0.0.1:9000/app — a common
 * iOS Simulator white-screen cause when APP_URL still says localhost.
 */
class WechirpNativeWebViewVite
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) config('services.wechirp.native_shell_ua_token', ''));
        if ($token === '') {
            return $next($request);
        }

        if (str_contains($request->userAgent() ?? '', $token)) {
            Vite::useHotFile(storage_path('framework/.vite-native-webview-no-hot'));
            URL::forceRootUrl($request->getSchemeAndHttpHost());
        }

        return $next($request);
    }
}
