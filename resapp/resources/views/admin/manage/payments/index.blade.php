@extends('admin.layouts.app')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Payment history</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Payment history for all users.</p>
    </div>

    <form method="GET" action="{{ route('admin.payments.index') }}"
        class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900 md:grid-cols-5">
        <input name="q" value="{{ $q }}" placeholder="Search email / transaction / product"
            class="h-11 rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 md:col-span-2" />
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
                        <th class="px-5 py-3">User</th>
                        <th class="px-5 py-3">Plan</th>
                        <th class="px-5 py-3">Transaction</th>
                        <th class="px-5 py-3">Platform</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Created</th>
                        <th class="px-5 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($payments as $p)
                        <tr>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $p->user?->email ?: '—' }}</td>
                            <td class="px-5 py-4 font-medium text-gray-800 dark:text-white/90">{{ $p->plan?->name ?: '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $p->transaction_id ?: '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $p->platform ?: '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $p->status }}</td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $p->created_at?->toDateTimeString() }}</td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('admin.subscriptions.show', $p) }}"
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
            {{ $payments->links() }}
        </div>
    </div>
@endsection

