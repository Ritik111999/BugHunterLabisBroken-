@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Support tickets</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Manage incoming messages.</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.support.index') }}"
                class="rounded-lg border px-4 py-2 text-sm font-medium {{ !$statusFilter ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-200 bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200' }}">
                All
            </a>
            <a href="{{ route('admin.support.index', ['status' => 'open']) }}"
                class="rounded-lg border px-4 py-2 text-sm font-medium {{ $statusFilter === 'open' ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-200 bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200' }}">
                Open
            </a>
            <a href="{{ route('admin.support.index', ['status' => 'resolved']) }}"
                class="rounded-lg border px-4 py-2 text-sm font-medium {{ $statusFilter === 'resolved' ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-200 bg-white text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200' }}">
                Resolved
            </a>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-5 py-3">From</th>
                        <th class="px-5 py-3">Subject</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($tickets as $t)
                        <tr>
                            <td class="px-5 py-4">
                                <div class="font-medium text-gray-800 dark:text-white/90">{{ $t->name }}</div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $t->email }}</div>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">
                                {{ $t->subject ?: '—' }}
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium
                                    {{ ($t->status ?? 'open') === 'resolved' ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' : 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300' }}">
                                    {{ $t->status ?? 'open' }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('admin.support.show', $t) }}"
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
            {{ $tickets->links() }}
        </div>
    </div>
@endsection

