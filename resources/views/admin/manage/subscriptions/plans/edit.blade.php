@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Edit plan</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $plan->code }}</p>
        </div>
        <a href="{{ route('admin.plans.index') }}"
            class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
            Back
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.plans.update', $plan) }}"
        class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Name</label>
                <input name="name" value="{{ old('name', $plan->name) }}"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            </div>
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Price (USD)</label>
                <input name="price_usd" value="{{ old('price_usd', $plan->price_usd) }}"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            </div>
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Currency</label>
                <input name="currency" value="{{ old('currency', $plan->currency) }}"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            </div>
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Billing cycle</label>
                <input name="billing_cycle" value="{{ old('billing_cycle', $plan->billing_cycle) }}"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            </div>
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Max participants</label>
                <input name="max_participants_per_meeting" value="{{ old('max_participants_per_meeting', $plan->max_participants_per_meeting) }}"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            </div>
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">History days</label>
                <input name="meeting_history_days" value="{{ old('meeting_history_days', $plan->meeting_history_days) }}"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-2">
            @foreach (['advanced_analytics' => 'Advanced analytics', 'transcript_search' => 'Transcript search', 'export_reports' => 'Export reports', 'is_active' => 'Active'] as $field => $label)
                <label class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                    <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $plan->$field))
                        class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500" />
                    <span class="font-medium">{{ $label }}</span>
                </label>
            @endforeach
        </div>

        <div class="mt-6">
            <button type="submit"
                class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                Save
            </button>
        </div>
    </form>
@endsection

