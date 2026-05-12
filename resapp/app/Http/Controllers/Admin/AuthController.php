<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('admin.pages.auth.signin', ['title' => 'Sign In']);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'nullable|boolean',
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);
        unset($credentials['remember']);

        $credentials['email'] = Str::lower(trim((string) $credentials['email']));

        if (Auth::attempt($credentials, $remember)) {
            $user = Auth::user();
            if (!$user || !$user->hasRole('admin')) {
                Auth::logout();

                return back()
                    ->withErrors([
                        'email' => 'This account is not an administrator. Sign in with a user that has the admin role (see UserAdminSeeder or `php artisan admin:create-user`).',
                    ])
                    ->onlyInput('email');
            }

            $request->session()->regenerate();

            return redirect()->intended('/admin');
        }

        return back()
            ->withErrors(['email' => 'Invalid credentials.'])
            ->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/signin');
    }
}

