@extends('admin.layouts.app')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Edit user</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $user->email }}</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.users.update', $user) }}"
        class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Account status</label>
                <select name="status"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                    <option value="active" @selected(($user->status ?? 'active') === 'active')>active</option>
                    <option value="suspended" @selected(($user->status ?? 'active') === 'suspended')>suspended</option>
                </select>
                @error('status')
                    <div class="mt-2 text-sm text-error-600">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Subscription status (optional)</label>
                <input name="subscription_status" value="{{ old('subscription_status', $user->subscription_status) }}"
                    placeholder="e.g. active / canceled / trial"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
                @error('subscription_status')
                    <div class="mt-2 text-sm text-error-600">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="mt-6">
            <div class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Roles</div>
            <div class="flex flex-wrap gap-2">
                @forelse ($user->roles as $role)
                    <span
                        class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1 text-sm font-medium text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                        {{ $role->name }}
                    </span>
                @empty
                    <span class="text-sm text-gray-500 dark:text-gray-400">No roles assigned.</span>
                @endforelse
            </div>
            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Roles are read-only in the admin panel.</div>
        </div>

        <div class="mt-6 flex items-center gap-3">
            <button type="submit"
                class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                Save changes
            </button>
            <a href="{{ route('admin.users.index') }}"
                class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                Back
            </a>
        </div>
    </form>
@endsection

