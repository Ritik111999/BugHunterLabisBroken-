@extends('admin.layouts.app')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Subscriptions</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Payment history for all users.</p>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach (['total' => 'Total', 'active' => 'Active', 'expired' => 'Expired', 'canceled' => 'Canceled'] as $k => $label)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $stats[$k] }}</div>
            </div>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ route('admin.subscriptions.index') }}"
            class="rounded-lg border px-4 py-2 text-sm font-medium {{ !$statusFilter ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-200 bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200' }}">
            All
        </a>
        @foreach (['active','expired','canceled'] as $s)
            <a href="{{ route('admin.subscriptions.index', array_filter(['status' => $s, 'platform' => $platformFilter])) }}"
                class="rounded-lg border px-4 py-2 text-sm font-medium {{ $statusFilter === $s ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-200 bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200' }}">
                {{ $s }}
            </a>
        @endforeach

        <div class="ml-auto flex items-center gap-2">
            <a href="{{ route('admin.plans.index') }}"
                class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                Manage plans
            </a>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-5 py-3">User</th>
                        <th class="px-5 py-3">Plan</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Platform</th>
                        <th class="px-5 py-3">Expires</th>
                        <th class="px-5 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($subs as $sub)
                        <tr>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $sub->user?->email ?: '—' }}</td>
                            <td class="px-5 py-4 font-medium text-gray-800 dark:text-white/90">{{ $sub->plan?->name ?: '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $sub->status }}</td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $sub->platform ?: '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ optional($sub->expires_at)->toDateTimeString() ?: '—' }}</td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('admin.subscriptions.show', $sub) }}"
                                    class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                                    View
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
            {{ $subs->links() }}
        </div>
    </div>
@endsection

