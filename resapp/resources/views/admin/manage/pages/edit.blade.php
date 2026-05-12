@extends('admin.layouts.app')

@section('content')
    @php
        $pageMeta = [
            'terms' => [
                'title' => 'Terms of Service',
                'subtitle' => 'Edit the terms shown to users in the app and website.',
            ],
            'privacy' => [
                'title' => 'Privacy Policy',
                'subtitle' => 'Update how you describe data usage and privacy to users.',
            ],
            'about' => [
                'title' => 'About Us',
                'subtitle' => 'Edit the About Us content shown to users.',
            ],
        ];

        $meta = $pageMeta[$page->type] ?? [
            'title' => 'Edit page',
            'subtitle' => 'Update this page content.',
        ];
    @endphp

    <div class="mb-6 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $meta['title'] }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $meta['subtitle'] }}</p>
        </div>
        <a href="{{ route('admin.pages.index') }}"
            class="inline-flex h-11 items-center justify-center rounded-lg border border-gray-200 bg-white px-5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
            Back
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.pages.update', $page->type) }}"
        class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
        @csrf
        @method('PUT')

        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Title</label>
        <input name="title" value="{{ old('title', $page->title) }}"
            class="mb-5 h-11 w-full rounded-lg border border-gray-200 bg-white px-4 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200" />

        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">Content</label>
        <textarea name="content" rows="14"
            class="w-full rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 outline-none focus:border-brand-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">{{ old('content', $page->content) }}</textarea>

        <div class="mt-5">
            <button type="submit"
                class="inline-flex h-11 items-center justify-center rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">
                Save
            </button>
        </div>
    </form>
@endsection

