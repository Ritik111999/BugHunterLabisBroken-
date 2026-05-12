<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $title }} | {{ config('app.name', 'WeChirp') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-white text-slate-900 antialiased">
    <div class="mx-auto flex min-h-[70vh] max-w-lg flex-col items-center justify-center px-4 py-16 text-center">
        <div class="mb-6 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-800">
            <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-black">{{ $title }}</h1>
        <p class="mt-4 text-sm leading-relaxed text-slate-600">
            Your account deletion request has been submitted successfully. Our team will review it and may follow up at the email address on your account if needed.
        </p>
        <p class="mt-8 text-xs text-slate-500">
            Support:
            <a href="mailto:{{ $supportEmail }}" class="font-medium text-emerald-900 hover:underline">{{ $supportEmail }}</a>
        </p>
        <p class="mt-10">
            <a href="{{ route('delete-account.show') }}" class="text-sm font-semibold text-emerald-900 hover:underline">Back to form</a>
        </p>
    </div>

    <footer class="mx-auto max-w-5xl border-t border-slate-200 px-4 py-8 text-center text-xs text-slate-500">
        <p class="text-slate-600">&copy; {{ date('Y') }} {{ config('app.name', 'WeChirp') }}. All rights reserved.</p>
        <p class="mt-3">
            <a href="{{ $privacyUrl }}" class="text-emerald-900 hover:underline">Privacy Policy</a>
            <span class="mx-2 text-slate-300">|</span>
            <a href="{{ $termsUrl }}" class="text-emerald-900 hover:underline">Terms &amp; Conditions</a>
            <span class="mx-2 text-slate-300">|</span>
            <a href="{{ $aboutUrl }}" class="text-emerald-900 hover:underline">About Us</a>
        </p>
    </footer>
</body>

</html>
