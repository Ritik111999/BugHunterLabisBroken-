<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $sttEnv = strtolower(trim((string) env('MEETING_STT_PROVIDER', 'deepgram')));
        if ($sttEnv !== '' && $sttEnv !== 'deepgram') {
            Log::warning('wechirp_stt_provider_deprecated', [
                'configured' => $sttEnv,
                'required' => 'deepgram',
                'message' => 'WeChirp uses Deepgram only. Set MEETING_STT_PROVIDER=deepgram.',
            ]);
        }

        Blade::anonymousComponentPath(resource_path('views/admin/components'));

        RateLimiter::for('public-delete-account', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('delete-account-otp', function (Request $request) {
            return Limit::perMinute(6)->by($request->ip());
        });

        RateLimiter::for('api-authenticated', function (Request $request) {
            $user = $request->user();
            $key = $user ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();
            $configured = (int) config('app.api_auth_requests_per_minute', 900);
            // Safe bounds: enough headroom for studio polling + chunks; cap prevents runaway .env values.
            $perMinute = max(300, min(2500, $configured > 0 ? $configured : 900));

            return Limit::perMinute($perMinute)->by($key);
        });
    }
}
