<?php

namespace App\Http\Middleware;

use App\Models\AccountDeletionRequest;
use App\Models\User;
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

        if ($user instanceof User && AccountDeletionRequest::hasPendingForUser($user)) {
            if (!$request->routeIs('api.logout')) {
                return response()->json([
                    'status' => false,
                    'message' => 'This account has a pending deletion request. Sign-in and API access are disabled until the request is cancelled or resolved.',
                ], 403);
            }
        }

        Auth::setUser($user);

        return $next($request);
    }
}