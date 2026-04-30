@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Meeting export</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Export meetings CSV with optional filters.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.meetings.export') }}"
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

    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm text-gray-700 dark:text-gray-200">
                Download a CSV of meetings matching the current filters.
            </div>
            <a href="{{ route('admin.meetings.export_download', request()->query()) }}"
                class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                Download meetings CSV
            </a>
        </div>
    </div>
@endsection

