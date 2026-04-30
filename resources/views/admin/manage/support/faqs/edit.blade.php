@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Edit FAQ</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Update the FAQ and save changes.</p>
        </div>
        <a href="{{ route('admin.support.faqs.index') }}"
            class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
            Back to FAQs
        </a>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
            <div class="font-medium">Please fix the errors below.</div>
            <ul class="mt-2 list-disc pl-5">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.support.faqs.update', $faq) }}"
        class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Question</label>
                <input name="question" value="{{ old('question', $faq->question) }}"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            </div>

            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Answer</label>
                <textarea name="answer" rows="8"
                    class="w-full rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">{{ old('answer', $faq->answer) }}</textarea>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Order</label>
                <input name="order" type="number" value="{{ old('order', $faq->order ?? 0) }}"
                    class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />
            </div>

            <div class="flex items-end">
                <label class="flex w-full items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', (bool) $faq->is_active))
                        class="h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500" />
                    Active
                </label>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-2">
            <button type="submit"
                class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                Save changes
            </button>
        </div>
    </form>
@endsection

