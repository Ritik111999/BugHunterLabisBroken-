@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">System logs</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Showing the last ~300 lines of <span class="font-mono">{{ $logPath }}</span>.</p>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        @if (!$lines)
            <div class="text-sm text-gray-500 dark:text-gray-400">No log file found yet.</div>
        @else
            <pre class="max-h-[70vh] overflow-auto rounded-lg bg-gray-50 p-4 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-200">{{ implode("\n", $lines) }}</pre>
        @endif
    </div>
@endsection

