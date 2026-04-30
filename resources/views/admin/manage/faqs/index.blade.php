@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">FAQs</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Manage FAQ content </p>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Add FAQ</div>
            <a href="{{ route('admin.support.faqs.index') }}"
                class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                View / Edit existing FAQs
            </a>
        </div>
        <form method="POST" action="{{ route('admin.faqs.store') }}" class="grid grid-cols-1 gap-3 md:grid-cols-2">
            @csrf
            <input name="question" placeholder="Question"
                class="h-11 rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 md:col-span-2" />
            <textarea name="answer" rows="4" placeholder="Answer"
                class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 md:col-span-2"></textarea>
            <input name="order" placeholder="Order" type="number"
                class="h-11 rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            <label class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                <input type="checkbox" name="is_active" value="1" checked
                    class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500" />
                Active
            </label>
            <button type="submit"
                class="md:col-span-2 inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                Create
            </button>
        </form>
    </div>
@endsection

