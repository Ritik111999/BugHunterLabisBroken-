@extends('admin.layouts.app')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Performance</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Basic operational stats (last 24h since {{ $since24h->toDateTimeString() }}). This is a lightweight view without external monitoring integrations.
        </p>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-sm text-gray-500 dark:text-gray-400">Users</div>
            <div class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $stats['users'] }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-sm text-gray-500 dark:text-gray-400">Meetings (24h / total)</div>
            <div class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $stats['meetings_24h'] }} / {{ $stats['meetings_total'] }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-sm text-gray-500 dark:text-gray-400">Subscriptions (24h / total)</div>
            <div class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $stats['subs_24h'] }} / {{ $stats['subs_total'] }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="text-sm text-gray-500 dark:text-gray-400">Open tickets</div>
            <div class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $stats['open_tickets'] }}</div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Queue</div>
            <div class="text-sm text-gray-700 dark:text-gray-200">Queued jobs: <span class="font-medium">{{ $stats['queued_jobs'] }}</span></div>
            <div class="text-sm text-gray-700 dark:text-gray-200">Failed jobs: <span class="font-medium">{{ $stats['failed_jobs'] }}</span></div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Logs</div>
            <div class="text-sm text-gray-700 dark:text-gray-200">laravel.log size: <span class="font-medium">{{ number_format($logSize / 1024, 1) }} KB</span></div>
            <div class="mt-3">
                <a href="{{ route('admin.logs') }}"
                    class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                    View logs
                </a>
            </div>
        </div>
    </div>
@endsection

