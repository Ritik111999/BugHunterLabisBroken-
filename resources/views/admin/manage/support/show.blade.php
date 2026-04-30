@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Ticket #{{ $ticket->id }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $ticket->email }}</p>
        </div>

        <a href="{{ route('admin.support.index') }}"
            class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
            Back
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Subject</div>
            <div class="text-gray-800 dark:text-white/90">{{ $ticket->subject ?: '—' }}</div>

            <div class="mt-5 mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Message</div>
            <div class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">{{ $ticket->message }}</div>
        </div>

        <form method="POST" action="{{ route('admin.support.update', $ticket) }}"
            class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
            @csrf
            @method('PUT')

            <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Status</label>
            <select name="status"
                class="h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
                <option value="open" @selected(($ticket->status ?? 'open') === 'open')>open</option>
                <option value="resolved" @selected(($ticket->status ?? 'open') === 'resolved')>resolved</option>
            </select>

            <label class="mt-5 mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Admin reply</label>
            <textarea name="admin_reply" rows="8"
                class="w-full rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">{{ old('admin_reply', $ticket->admin_reply) }}</textarea>

            <div class="mt-5 flex items-center gap-3">
                <button type="submit"
                    class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                    Save
                </button>
            </div>
        </form>
    </div>
@endsection

