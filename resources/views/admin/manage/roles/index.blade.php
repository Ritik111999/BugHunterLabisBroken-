@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Roles</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Roles are read-only. Assign roles from the user edit page.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="min-w-full">
            <thead class="bg-gray-50 dark:bg-gray-800/50">
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="px-5 py-3">Name</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($roles as $role)
                    <tr>
                        <td class="px-5 py-4 font-medium text-gray-800 dark:text-white/90">{{ $role->name }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection

