@extends('admin.layouts.app')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Pricing plans</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Manage subscription plans.</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-5 py-3">Code</th>
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Price</th>
                        <th class="px-5 py-3">Active</th>
                        <th class="px-5 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($plans as $p)
                        <tr>
                            <td class="px-5 py-4 font-medium text-gray-800 dark:text-white/90">{{ $p->code }}</td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $p->name }}</td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $p->price_usd }} {{ $p->currency }} / {{ $p->billing_cycle }}</td>
                            <td class="px-5 py-4 text-sm text-gray-700 dark:text-gray-200">{{ $p->is_active ? 'yes' : 'no' }}</td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('admin.plans.edit', $p) }}"
                                    class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection

