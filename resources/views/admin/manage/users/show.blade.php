@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">User details</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $user->email }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.users.edit', $user) }}"
                class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                Edit
            </a>
            <a href="{{ route('admin.users.index') }}"
                class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                Back
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Account</div>
                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium
                    {{ ($user->status ?? 'active') === 'suspended' ? 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300' : 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' }}">
                    {{ $user->status ?? 'active' }}
                </span>
            </div>

            <div class="space-y-3 text-sm">
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">Name</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $user->name }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">Phone</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $user->phone ?: '—' }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">Subscription status</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $user->subscription_status ?: '—' }}</span>
                </div>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">Roles</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">
                        {{ $user->roles->pluck('name')->join(', ') ?: '—' }}
                    </span>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-200">Reset password</div>
            <form method="POST" action="{{ route('admin.users.reset_password', $user) }}" class="flex flex-col gap-3">
                @csrf
                <input name="password" type="password" placeholder="New password (min 8 chars)"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
                @error('password')
                    <div class="text-sm text-error-600">{{ $message }}</div>
                @enderror
                <button type="submit"
                    class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                    Reset password
                </button>
            </form>

            <div class="mt-6 border-t border-gray-100 pt-6 dark:border-gray-800">
                <div class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">Danger zone</div>
                <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                    onsubmit="return confirm('Delete this user? This cannot be undone.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                        class="inline-flex h-11 items-center justify-center rounded-lg border border-error-200 bg-white px-5 text-sm font-medium text-error-700 hover:bg-error-50 dark:border-error-500/30 dark:bg-gray-900 dark:text-error-300 dark:hover:bg-error-500/10">
                        Delete user
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-200">Subscription history</div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Plan</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4">Platform</th>
                        <th class="py-2 pr-4">Expires</th>
                        <th class="py-2 pr-4">Transaction</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($user->subscriptions as $sub)
                        <tr>
                            <td class="py-3 pr-4 font-medium text-gray-800 dark:text-white/90">
                                {{ $sub->plan?->name ?? '—' }}
                            </td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $sub->status }}</td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $sub->platform ?: '—' }}</td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ optional($sub->expires_at)->toDateTimeString() ?: '—' }}</td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-200">{{ $sub->transaction_id ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-3 text-gray-500 dark:text-gray-400" colspan="5">No subscriptions found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

