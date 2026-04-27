<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    // ✅ Get Profile
    public function profile(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => $request->user(),
        ]);
    }

    // ✅ Update Profile
   public function update(Request $request)
{
    $user = $request->user();

    if (!$user) {
        return response()->json([
            'status' => 'failed',
            'message' => 'Unauthorized'
        ], 401);
    }

    // ❗ Type check
    if ($request->has('avatar') && !$request->hasFile('avatar')) {
        return response()->json([
            'status' => 'failed',
            'message' => 'Invalid file upload',
        ], 422);
    }

    $validator = Validator::make(
        $request->all(),
        [
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg',
        ]
    );

    if ($validator->fails()) {
        return response()->json([
            'status' => 'failed',
            'message' => $validator->errors()->first(),
        ], 422);
    }

    if ($request->hasFile('avatar')) {
        $path = $request->file('avatar')->store('avatars', 'public');
        $user->avatar = $path;
    }

    $user->update($request->only(['name', 'phone']));

    return response()->json([
        'status' => 'success',
        'message' => 'Profile updated successfully',
        'data' => $user,
    ]);
}

    // ✅ Change Password
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized'
            ], 401);
        }

        // ❌ Wrong current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Current password is incorrect',
            ], 400);
        }

        // ✅ Update password
        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully',
        ]);
    }
}