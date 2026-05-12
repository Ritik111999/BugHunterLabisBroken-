<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect('/admin/signin');
        }

        if (!$user->hasRole('admin')) {
            Auth::logout();

            return redirect()
                ->route('admin.auth.signin')
                ->withErrors(['email' => 'Administrator access required. Sign in with an admin account.']);
        }

        return $next($request);
    }
}

