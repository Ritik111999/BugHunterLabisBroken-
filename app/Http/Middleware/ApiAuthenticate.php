<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class ApiAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('sanctum')->user();

        // EventSource can't send Authorization header; allow `?token=` for SSE endpoints.
        if (!$user) {
            $token = (string) $request->query('token', '');
            if ($token !== '') {
                $pat = PersonalAccessToken::findToken($token);
                if ($pat?->tokenable) {
                    $user = $pat->tokenable;
                }
            }
        }

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

    
        Auth::setUser($user);

        return $next($request);
    }
}