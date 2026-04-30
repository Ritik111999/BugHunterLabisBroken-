@extends('admin.layouts.app')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Settings</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Key/value settings and feature flags (keys prefixed with <span class="font-medium">feature.</span>).</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-200">Add / update setting</div>
            <form method="POST" action="{{ route('admin.settings.store') }}" class="space-y-3">
                @csrf
                <input name="key" placeholder="key (e.g. app.name or feature.new_ui)"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
                <textarea name="value" rows="4" placeholder="value"
                    class="w-full rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200"></textarea>
                <button type="submit"
                    class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                    Save
                </button>
            </form>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 text-sm font-medium text-gray-700 dark:text-gray-200">Feature flags</div>
            <div class="space-y-2">
                @forelse ($flags as $flag)
                    <form method="POST" action="{{ route('admin.settings.toggle_flag') }}"
                        class="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                        @csrf
                        <input type="hidden" name="key" value="{{ $flag->key }}" />
                        <div class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $flag->key }}</div>
                        <button type="submit"
                            class="rounded-lg px-3 py-2 text-sm font-medium {{ $flag->value === '1' ? 'bg-success-500 text-white' : 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-200' }}">
                            {{ $flag->value === '1' ? 'ON' : 'OFF' }}
                        </button>
                    </form>
                @empty
                    <div class="text-sm text-gray-500 dark:text-gray-400">No feature flags yet. Add a setting with key like <span class="font-medium">feature.some_flag</span>.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="min-w-full">
            <thead class="bg-gray-50 dark:bg-gray-800/50">
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="px-5 py-3">Key</th>
                    <th class="px-5 py-3">Value</th>
                    <th class="px-5 py-3 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($settings as $s)
                    <tr>
                        <td class="px-5 py-4 font-medium text-gray-800 dark:text-white/90">{{ $s->key }}</td>
                        <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">
                            <div class="max-w-[520px] truncate">{{ $s->value }}</div>
                        </td>
                        <td class="px-5 py-4 text-right">
                            <form method="POST" action="{{ route('admin.settings.destroy', $s) }}" class="inline"
                                onsubmit="return confirm('Delete this setting?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                    class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection

