@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Feature flags</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">All keys under <span class="font-mono">feature.*</span> in `settings`.</p>
        </div>
        <a href="{{ route('admin.settings.app') }}"
            class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
            App settings
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">Add a feature flag</div>
        <form method="POST" action="{{ route('admin.settings.flags.store') }}" class="flex flex-col gap-3 sm:flex-row">
            @csrf
            <input name="key" placeholder="feature.my_flag (or just my_flag)"
                class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            <button type="submit"
                class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                Add
            </button>
        </form>
    </div>

    <div class="space-y-2">
        @forelse ($flags as $flag)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $flag->key }}</div>
                <div class="flex items-center gap-2">
                    <form method="POST" action="{{ route('admin.settings.flags.toggle') }}">
                        @csrf
                        <input type="hidden" name="key" value="{{ $flag->key }}" />
                        <button type="submit"
                            class="rounded-lg px-4 py-2 text-sm font-medium {{ $flag->value === '1' ? 'bg-success-500 text-white' : 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-200' }}">
                            {{ $flag->value === '1' ? 'ON' : 'OFF' }}
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.settings.destroy', $flag) }}" onsubmit="return confirm('Delete this feature flag?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <div class="text-sm text-gray-500 dark:text-gray-400">No feature flags yet.</div>
        @endforelse
    </div>
@endsection

