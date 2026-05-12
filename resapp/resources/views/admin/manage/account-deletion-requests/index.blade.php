@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Account deletion requests</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Review requests from the mobile app or public web form.</p>
        </div>

        <form method="GET" action="{{ route('admin.account-deletion-requests.index') }}" class="flex flex-wrap gap-2">
            <select name="status"
                class="h-11 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                <option value="">All statuses</option>
                <option value="pending" @selected($filterStatus === 'pending')>Pending</option>
                <option value="approved" @selected($filterStatus === 'approved')>Approved</option>
                <option value="rejected" @selected($filterStatus === 'rejected')>Rejected</option>
                <option value="cancelled" @selected($filterStatus === 'cancelled')>Cancelled</option>
            </select>
            <button type="submit"
                class="h-11 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">Filter</button>
        </form>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-5 py-3">Source</th>
                        <th class="px-5 py-3">User / contact</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Reason</th>
                        <th class="px-5 py-3">Requested</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($requests as $req)
                        <tr>
                            <td class="px-5 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium
                                    {{ $req->source === 'app' ? 'bg-blue-50 text-blue-800 dark:bg-blue-500/10 dark:text-blue-300' : 'bg-purple-50 text-purple-800 dark:bg-purple-500/10 dark:text-purple-300' }}">
                                    {{ $req->source }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="font-medium text-gray-800 dark:text-white/90">{{ $req->name }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $req->email }}</div>
                                @if ($req->phone)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $req->phone }}</div>
                                @endif
                                @if ($req->user)
                                    <div class="mt-1 text-xs text-gray-400">User ID: {{ $req->user->id }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium capitalize
                                    @class([
                                        'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' => $req->status === 'pending',
                                        'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' => $req->status === 'approved',
                                        'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300' => $req->status === 'rejected',
                                        'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => $req->status === 'cancelled',
                                    ])">
                                    {{ $req->status }}
                                </span>
                            </td>
                            <td class="max-w-xs px-5 py-4 text-sm text-gray-600 dark:text-gray-300">
                                {{ \Illuminate\Support\Str::limit($req->reason ?? '—', 120) }}
                            </td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ $req->created_at?->format('Y-m-d H:i') }}
                            </td>
                            <td class="px-5 py-4 text-right">
                                @if ($req->status === 'pending')
                                    <div class="flex flex-col items-end gap-2 sm:flex-row sm:justify-end">
                                        <form method="POST" action="{{ route('admin.account-deletion-requests.approve', $req) }}">
                                            @csrf
                                            <button type="submit"
                                                class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-success-600">Approve</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.account-deletion-requests.reject', $req) }}">
                                            @csrf
                                            <button type="submit"
                                                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">Reject</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.account-deletion-requests.delete-user', $req) }}">
                                            @csrf
                                            <button type="submit"
                                                class="rounded-lg bg-error-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-error-600">Delete user</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No requests yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
            {{ $requests->links() }}
        </div>
    </div>
@endsection
