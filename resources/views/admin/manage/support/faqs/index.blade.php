@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">FAQs</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">View and edit FAQs </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.faqs.index') }}"
                class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                Add FAQ
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                            Question
                        </th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                            Order
                        </th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                            Status
                        </th>
                        <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                            Action
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    @forelse ($faqs as $faq)
                        <tr>
                            <td class="px-5 py-4">
                                <div class="text-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $faq->question }}
                                </div>
                                <div class="mt-1 line-clamp-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $faq->answer }}
                                </div>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">
                                {{ $faq->order ?? 0 }}
                            </td>
                            <td class="px-5 py-4">
                                @if ($faq->is_active)
                                    <span
                                        class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700 dark:bg-green-500/15 dark:text-green-300">
                                        Active
                                    </span>
                                @else
                                    <span
                                        class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                        Hidden
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('admin.support.faqs.edit', $faq) }}"
                                    class="inline-flex h-10 items-center justify-center rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-10 text-center text-sm text-gray-600 dark:text-gray-300">
                                No FAQs found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

