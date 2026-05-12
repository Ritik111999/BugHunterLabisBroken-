<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->with('roles')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($q2) use ($q) {
                    $q2->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.manage.users.index', [
            'title' => 'Users',
            'users' => $users,
            'q' => $q,
        ]);
    }

    public function show(User $user)
    {
        return view('admin.manage.users.show', [
            'title' => 'User Details',
            'user' => $user->load(['roles', 'subscriptions.plan']),
        ]);
    }

    public function edit(User $user)
    {
        return view('admin.manage.users.edit', [
            'title' => 'Edit User',
            'user' => $user->load('roles'),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'status' => 'nullable|in:active,suspended',
            'subscription_status' => 'nullable|string|max:255',
        ]);

        if (array_key_exists('status', $data)) {
            $user->status = $data['status'] ?? $user->status;
        }
        if (array_key_exists('subscription_status', $data)) {
            $user->subscription_status = $data['subscription_status'];
        }
        $user->save();

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', 'User updated.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $user->password = \Illuminate\Support\Facades\Hash::make($data['password']);
        $user->save();

        return redirect()->route('admin.users.show', $user)->with('status', 'Password reset.');
    }

    public function destroy(User $user)
    {
        // Keep it simple/safe: prevent deleting yourself.
        if (auth()->id() === $user->id) {
            return redirect()->route('admin.users.show', $user)->with('status', 'You cannot delete your own account.');
        }

        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->roles()->detach();
        $user->name = 'Deleted user';
        $user->phone = null;
        $user->email = 'deleted.'.$user->id.'.'.Str::lower(Str::random(12)).'@users.invalid';
        $user->status = 'deleted';
        $user->save();
        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'User deleted.');
    }
}

