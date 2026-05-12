@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Subscription #{{ $sub->id }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $sub->user?->email ?: '—' }}</p>
        </div>
        <a href="{{ route('admin.subscriptions.index') }}"
            class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
            Back
        </a>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">Details</div>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><span class="text-gray-500 dark:text-gray-400">Plan</span><span class="font-medium text-gray-800 dark:text-white/90">{{ $sub->plan?->name ?: '—' }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-gray-500 dark:text-gray-400">Status</span><span class="font-medium text-gray-800 dark:text-white/90">{{ $sub->status }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-gray-500 dark:text-gray-400">Platform</span><span class="font-medium text-gray-800 dark:text-white/90">{{ $sub->platform ?: '—' }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-gray-500 dark:text-gray-400">Started</span><span class="font-medium text-gray-800 dark:text-white/90">{{ optional($sub->started_at)->toDateTimeString() ?: '—' }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-gray-500 dark:text-gray-400">Expires</span><span class="font-medium text-gray-800 dark:text-white/90">{{ optional($sub->expires_at)->toDateTimeString() ?: '—' }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-gray-500 dark:text-gray-400">Transaction</span><span class="font-medium text-gray-800 dark:text-white/90">{{ $sub->transaction_id ?: '—' }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-gray-500 dark:text-gray-400">Product</span><span class="font-medium text-gray-800 dark:text-white/90">{{ $sub->product_id ?: '—' }}</span></div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">Refunds</div>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                For now, refunds are handled as an admin action that marks this subscription as <span class="font-medium">refunded</span> and stores refund metadata in <span class="font-mono">raw_payload.refund</span>.
                Provider-side refunds (Apple/Google) can be integrated later without schema changes.
            </div>

            <form method="POST" action="{{ route('admin.subscriptions.refund', $sub) }}" class="mt-4 space-y-3">
                @csrf
                <textarea name="note" rows="4" placeholder="Refund note (optional)"
                    class="w-full rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200"></textarea>
                <button type="submit"
                    class="inline-flex h-11 items-center justify-center rounded-lg border border-error-200 bg-white px-5 text-sm font-medium text-error-700 hover:bg-error-50 dark:border-error-500/30 dark:bg-gray-900 dark:text-error-300 dark:hover:bg-error-500/10"
                    onclick="return confirm('Mark this subscription as refunded?');">
                    Mark refunded
                </button>
            </form>
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">Raw payload</div>
        <pre class="max-h-[420px] overflow-auto rounded-lg bg-gray-50 p-4 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-200">{{ json_encode($sub->raw_payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
@endsection

