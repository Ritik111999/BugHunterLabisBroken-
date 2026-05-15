<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <title>FAQs</title>
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-gray-50 text-gray-900">
    <div class="mx-auto max-w-3xl px-6 py-12">
        <div class="mb-8">
            <h1 class="text-3xl font-semibold">Frequently Asked Questions</h1>
        </div>

        <div class="space-y-4">
            @forelse ($faqs as $faq)
                <details class="rounded-xl border border-gray-200 bg-white p-5">
                    <summary class="cursor-pointer text-base font-medium">
                        {{ $faq->question }}
                    </summary>
                    <div class="mt-3 whitespace-pre-wrap text-sm text-gray-700">
                        {{ $faq->answer }}
                    </div>
                </details>
            @empty
                <div class="rounded-xl border border-gray-200 bg-white p-5 text-gray-700">
                    No FAQs published yet.
                </div>
            @endforelse
        </div>
    </div>
    @include('partials.pwa-register')
</body>

</html>

