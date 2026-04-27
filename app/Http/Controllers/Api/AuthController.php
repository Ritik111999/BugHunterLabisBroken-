<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

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

        return response()->json([
            'status'  => 'success',
            'message' => 'User registered successfully',
            'data'    => array_merge($user->toArray(), [
                'token' => $token,
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

        return response()->json([
            'status'  => 'success',
            'message' => 'Login successful',
            'data'    => array_merge($user->toArray(), [
                'token' => $token,
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
}