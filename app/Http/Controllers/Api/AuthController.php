<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\UserSubscription;

class AuthController extends Controller
{
    // ✅ Signup
    public function signup(Request $request)
    {
        if ($request->password !== $request->password_confirmation) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Password and confirm password do not match',
            ], 422);
        }

        if (User::where('email', strtolower($request->email))->exists()) {
            return response()->json([
                'status' => 'failed',
                'message' => 'User already exists with this email',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => strtolower($request->email),
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;
        $sub = $user->activeSubscription()->with('plan')->first();
        $plan = $user->currentPlan();

        return response()->json([
            'status'  => 'success',
            'message' => 'User registered successfully',
            'data'    => array_merge($user->toArray(), [
                'token' => $token,
                'subscription' => $this->subscriptionPayload($sub, $plan),
            ]),
        ]);
    }

    // ✅ Login
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ], [
            'email.required' => 'Email is required',
            'password.required' => 'Password is required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $email = strtolower($request->email);
        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'User not found',
            ], 404);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Incorrect password',
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $sub = $user->activeSubscription()->with('plan')->first();
        $plan = $user->currentPlan();

        return response()->json([
            'status'  => 'success',
            'message' => 'Login successful',
            'data'    => array_merge($user->toArray(), [
                'token' => $token,
                'subscription' => $this->subscriptionPayload($sub, $plan),
            ]),
        ]);
    }

    // ✅ Logout
    public function logout(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized'
            ], 401);
        }

        $user->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function subscriptionPayload(?UserSubscription $sub, $plan): array
    {
        $isPremium = $sub !== null;

        return [
            'is_premium' => $isPremium,
            'expires_at' => $sub?->expires_at?->toISOString(),
            'platform' => $sub?->platform,
            'product_id' => $sub?->product_id,
            'plan' => $plan ? [
                'id' => (int) $plan->id,
                'code' => (string) $plan->code,
                'name' => (string) $plan->name,
                'price_usd' => (float) $plan->price_usd,
                'currency' => (string) $plan->currency,
                'billing_cycle' => (string) $plan->billing_cycle,
                'max_participants_per_meeting' => $plan->max_participants_per_meeting,
                'meeting_history_days' => $plan->meeting_history_days,
                'advanced_analytics' => (bool) $plan->advanced_analytics,
                'transcript_search' => (bool) $plan->transcript_search,
                'export_reports' => (bool) $plan->export_reports,
                'trial_days' => (int) $plan->trial_days,
            ] : null,
        ];
    }
}