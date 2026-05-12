<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use Carbon\Carbon;
use App\Mail\SendOtpMail;

class ForgotPasswordController extends Controller
{
    // ✅ 1. Send OTP
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $otp = rand(100000, 999999);

        DB::table('password_resets')->updateOrInsert(
            ['email' => $request->email],
            [
                'otp' => $otp,
                'is_verified' => false, // 🔥 reset verification
                'expires_at' => Carbon::now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Mail::to($request->email)->send(new SendOtpMail($otp));

        return response()->json([
            'status' => 'success',
            'message' => 'OTP sent to your email',
        ]);
    }

    // ✅ 2. Verify OTP
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $record = DB::table('password_resets')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->first();

        if (!$record) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Invalid OTP',
            ], 400);
        }

        if (Carbon::now()->gt($record->expires_at)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'OTP expired',
            ], 400);
        }

        // ✅ Mark as verified
        DB::table('password_resets')
            ->where('email', $request->email)
            ->update(['is_verified' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'OTP verified successfully',
        ]);
    }

    // ✅ 3. Reset Password (SECURE)
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'password' => 'required|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        // ✅ Check verified OTP
        $record = DB::table('password_resets')
            ->where('email', $request->email)
            ->where('is_verified', true)
            ->first();

        if (!$record) {
            return response()->json([
                'status' => 'failed',
                'message' => 'OTP verification required',
            ], 403);
        }

        if (Carbon::now()->gt($record->expires_at)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'OTP expired',
            ], 400);
        }

        $user = User::where('email', $request->email)->first();

        $user->password = Hash::make($request->password);
        $user->save();

        // ✅ Clean up
        DB::table('password_resets')->where('email', $request->email)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset successfully',
        ]);
    }
}