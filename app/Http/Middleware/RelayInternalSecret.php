<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RelayInternalSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = trim((string) config('services.relay_gateway.internal_secret', ''));
        if ($expected === '') {
            return response()->json(['message' => 'Relay gateway not configured'], 503);
        }

        $provided = trim((string) $request->header('X-Relay-Secret', ''));
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
