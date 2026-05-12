@extends('admin.layouts.app')

@php
    $mask = function (?string $v): string {
        $v = $v ?? '';
        if ($v === '') return '';
        if (strlen($v) <= 6) return str_repeat('*', strlen($v));
        return substr($v, 0, 3) . str_repeat('*', max(3, strlen($v) - 6)) . substr($v, -3);
    };
@endphp

@section('content')
    <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">App settings</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Manage app + AI keys (stored in `settings`).</p>
        </div>
        <a href="{{ route('admin.settings.flags') }}"
            class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
            Feature flags
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-200">Quick edit (common keys)</div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @foreach ($knownKeys as $key)
                @php($val = $settings[$key]->value ?? '')
                <form method="POST" action="{{ route('admin.settings.app.store') }}" class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                    @csrf
                    <input type="hidden" name="key" value="{{ $key }}" />
                    <div class="mb-2 text-sm font-medium text-gray-800 dark:text-white/90">{{ $key }}</div>
                    <input name="value"
                        value="{{ str_starts_with($key, 'ai.') && str_contains($key, 'api_key') ? $mask($val) : $val }}"
                        placeholder="(empty)"
                        class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
                    <div class="mt-2 flex justify-end">
                        <button type="submit"
                            class="inline-flex h-10 items-center justify-center rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">
                            Save
                        </button>
                    </div>
                    @if (str_starts_with($key, 'ai.') && str_contains($key, 'api_key'))
                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            Note: masked display. Paste the full key to replace it.
                        </div>
                    @endif
                </form>
            @endforeach
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-200">Add / update any setting</div>
        <form method="POST" action="{{ route('admin.settings.app.store') }}" class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @csrf
            <input name="key" placeholder="key (e.g. ai.some_key)"
                class="h-11 rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            <input name="value" placeholder="value"
                class="h-11 rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            <button type="submit"
                class="md:col-span-2 inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-gray-50 px-5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-800 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10">
                Save
            </button>
        </form>
    </div>
@endsection

