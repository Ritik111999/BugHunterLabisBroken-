<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $title }} | {{ config('app.name', 'WeChirp') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-white text-slate-900 antialiased">
    <div class="mx-auto max-w-3xl px-4 py-12">
        <a href="{{ route('delete-account.show') }}" class="text-sm text-emerald-800 hover:underline">&larr; Back to account deletion</a>
        <h1 class="mt-6 text-2xl font-bold text-slate-900">{{ $title }}</h1>
        <div class="mt-8 max-w-none text-sm leading-relaxed text-slate-700 [&_a]:text-emerald-900 [&_a]:underline [&_h1]:mb-4 [&_h1]:text-xl [&_h1]:font-bold [&_h2]:mb-3 [&_h2]:mt-6 [&_h2]:text-lg [&_h2]:font-semibold [&_p]:mb-3 [&_ul]:mb-3 [&_ul]:list-disc [&_ul]:pl-5">
            {!! $content !!}
        </div>
    </div>
</body>

</html>
