@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Meetings</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Search by title/host, filter by date, export CSV.</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.meetings.export', request()->query()) }}"
                class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                Meeting export
            </a>
            <a href="{{ route('admin.meetings.export_analytics', request()->query()) }}"
                class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                Analytics export
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <form method="GET" action="{{ route('admin.meetings.index') }}"
        class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900 md:grid-cols-4">
        <input name="q" value="{{ $q }}" placeholder="Search title/host"
            class="h-11 rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
        <input name="from" value="{{ $from }}" type="date"
            class="h-11 rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
        <input name="to" value="{{ $to }}" type="date"
            class="h-11 rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
        <button type="submit"
            class="h-11 rounded-lg border border-gray-200 bg-gray-50 px-4 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-800 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10">
            Apply
        </button>
    </form>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-5 py-3">Title</th>
                        <th class="px-5 py-3">Host</th>
                        <th class="px-5 py-3">Started</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($meetings as $m)
                        <tr>
                            <td class="px-5 py-4 font-medium text-gray-800 dark:text-white/90">
                                {{ $m->title ?: '—' }}
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">
                                {{ $m->host?->email ?: '—' }}
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">
                                {{ optional($m->started_at)->toDateTimeString() ?: '—' }}
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $m->status }}</td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('admin.meetings.show', $m) }}"
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
            {{ $meetings->links() }}
        </div>
    </div>
@endsection

