@extends('admin.layouts.app')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Admin dashboard</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Quick overview across users, meetings, subscriptions, and support.</p>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <a href="{{ route('admin.users.index') }}" class="rounded-xl border border-gray-200 bg-white p-4 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-white/5">
            <div class="text-sm text-gray-500 dark:text-gray-400">Users</div>
            <div class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $stats['users'] }}</div>
        </a>
        <a href="{{ route('admin.meetings.index') }}" class="rounded-xl border border-gray-200 bg-white p-4 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-white/5">
            <div class="text-sm text-gray-500 dark:text-gray-400">Meetings</div>
            <div class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $stats['meetings'] }}</div>
        </a>
        <a href="{{ route('admin.subscriptions.index') }}" class="rounded-xl border border-gray-200 bg-white p-4 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-white/5">
            <div class="text-sm text-gray-500 dark:text-gray-400">Active subs</div>
            <div class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $stats['active_subs'] }}</div>
        </a>
        <a href="{{ route('admin.support.index', ['status' => 'open']) }}" class="rounded-xl border border-gray-200 bg-white p-4 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-white/5">
            <div class="text-sm text-gray-500 dark:text-gray-400">Open tickets</div>
            <div class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $stats['open_tickets'] }}</div>
        </a>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Recent meetings</div>
                <a class="text-sm font-medium text-brand-500 hover:text-brand-600" href="{{ route('admin.meetings.index') }}">View all</a>
            </div>
            <div class="space-y-3">
                @foreach ($recentMeetings as $m)
                    <a href="{{ route('admin.meetings.show', $m) }}" class="block rounded-lg border border-gray-200 px-4 py-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                        <div class="font-medium text-gray-800 dark:text-white/90">{{ $m->title ?: '—' }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $m->host?->email ?: '—' }} • {{ optional($m->started_at)->toDateTimeString() ?: '—' }}</div>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Recent tickets</div>
                <a class="text-sm font-medium text-brand-500 hover:text-brand-600" href="{{ route('admin.support.index') }}">View all</a>
            </div>
            <div class="space-y-3">
                @foreach ($recentTickets as $t)
                    <a href="{{ route('admin.support.show', $t) }}" class="block rounded-lg border border-gray-200 px-4 py-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                        <div class="font-medium text-gray-800 dark:text-white/90">{{ $t->subject ?: '—' }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $t->email }} • {{ $t->status ?? 'open' }}</div>
                    </a>
                @endforeach
            </div>
            <div class="mt-5 text-sm text-gray-500 dark:text-gray-400">
                Failed jobs: <span class="font-medium text-gray-800 dark:text-white/90">{{ $failedJobs }}</span>
            </div>
        </div>
    </div>
@endsection

