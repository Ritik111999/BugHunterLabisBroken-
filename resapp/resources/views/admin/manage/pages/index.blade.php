@extends('admin.layouts.app')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Pages</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Edit Terms / Privacy </p>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="min-w-full">
            <thead class="bg-gray-50 dark:bg-gray-800/50">
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="px-5 py-3">Type</th>
                    <th class="px-5 py-3">Title</th>
                    <th class="px-5 py-3 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($pages as $p)
                    <tr>
                        <td class="px-5 py-4 font-medium text-gray-800 dark:text-white/90">{{ $p->type }}</td>
                        <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $p->title }}</td>
                        <td class="px-5 py-4 text-right">
                            <a href="{{ route('admin.pages.edit', $p->type) }}"
                                class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                                Edit
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection

